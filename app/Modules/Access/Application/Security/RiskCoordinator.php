<?php

namespace App\Modules\Access\Application\Security;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Security\RiskAssessment;
use App\Modules\Access\Domain\Security\RiskLevel;
use App\Modules\Access\Domain\Security\RiskResponse;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class RiskCoordinator
{
    public function __construct(
        private DeterministicRiskEngine $engine,
        private SecurityAuditService $audit,
        private SecurityAlertService $alerts,
        private TemporaryAuthorization $authorizations,
        private OutboxDispatcher $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $signals
     */
    public function assessAndRespond(
        string $eventType,
        User $user,
        ?AuthSession $session,
        array $signals,
    ): RiskAssessment {
        $assessment = $this->engine->assess($eventType, $signals);

        return DB::transaction(function () use ($eventType, $user, $session, $signals, $assessment): RiskAssessment {
            if ($assessment->response === RiskResponse::REQUIRE_MFA && $session !== null) {
                $this->authorizations->invalidateSession($session, 'RISK_REQUIRES_MFA');
            } elseif ($assessment->response === RiskResponse::REJECT_AND_REVOKE_SESSION && $session !== null) {
                $this->revokeSession($session);
            } elseif ($assessment->response === RiskResponse::REVOKE_ALL_AND_OPEN_INCIDENT) {
                $this->revokeAll($user);
                if (($signals['compromise_account'] ?? false) === true
                    && (bool) config('access.risk.suspend_on_critical_compromise', true)) {
                    $user->forceFill([
                        'state' => AccountState::SECURITY_SUSPENDED->value,
                        'context_version' => $user->context_version + 1,
                    ])->save();
                }
            }

            $event = $this->audit->record($eventType, $assessment->response->value, $user, $user, [
                'session_id' => $session?->id,
                'application' => $session?->application,
                'branch_id' => $user->branch_id,
                'risk_level' => $assessment->level->value,
                'rule' => implode(',', $assessment->matchedRules),
                'counter' => is_int($signals['recent_failures'] ?? null) ? $signals['recent_failures'] : null,
                'reason' => is_string($signals['reason'] ?? null) ? $signals['reason'] : null,
                'metadata' => [
                    'matched_rules' => $assessment->matchedRules,
                    'score' => $assessment->score,
                    'response' => $assessment->response->value,
                ],
            ]);

            if (in_array($assessment->level, [RiskLevel::HIGH, RiskLevel::CRITICAL], true)) {
                $this->alerts->open(
                    $event,
                    $user,
                    is_string($user->branch_id) ? $user->branch_id : null,
                    $assessment->level->value,
                    $eventType,
                    'Se detectó un evento de seguridad que requiere revisión.',
                );
                $this->outbox->record(
                    'SECURITY_ALERT',
                    'security-alert:'.$event->event_uuid,
                    [
                        'event_id' => $event->event_uuid,
                        'risk_level' => $assessment->level->value,
                        'official_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/seguridad',
                    ],
                    $user->email,
                    'security-alert',
                );
            }

            return $assessment;
        });
    }

    private function revokeSession(AuthSession $session): void
    {
        $this->authorizations->invalidateSession($session, 'RISK_SESSION_REVOKED');
        RefreshToken::query()->where('auth_session_id', $session->id)->update([
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);
        PersonalAccessToken::query()->where('auth_session_id', $session->id)->delete();
        $session->forceFill(['state' => 'REVOKED', 'revoked_at' => now()])->save();
    }

    private function revokeAll(User $user): void
    {
        $this->authorizations->invalidateUser($user, 'CRITICAL_RISK');
        $sessionIds = AuthSession::query()->where('user_id', $user->id)->pluck('id');
        RefreshToken::query()->whereIn('auth_session_id', $sessionIds)->update([
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);
        PersonalAccessToken::query()->whereIn('auth_session_id', $sessionIds)->delete();
        AuthSession::query()->whereIn('id', $sessionIds)->update([
            'state' => 'REVOKED',
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
