<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\RiskDelinquency\Application\Contracts\OrganizationScopePort;
use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use App\Modules\RiskDelinquency\Application\Contracts\RiskReauthenticationPort;
use App\Modules\RiskDelinquency\Domain\Enums\DelinquencyStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertType;
use App\Modules\RiskDelinquency\Domain\Enums\RiskProfileStatus;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyDecision;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ApplyDistributorDelinquency
{
    public function __construct(
        private readonly OrganizationScopePort $scope,
        private readonly RiskReauthenticationPort $reauthentication,
        private readonly RiskClock $clock,
        private readonly RiskHttpIdempotency $idempotency,
        private readonly RiskRecorder $recorder,
    ) {}

    public function apply(
        User $actor,
        string $alertId,
        string $reauthenticationToken,
        string $idempotencyKey,
        ?string $reason,
    ): DelinquencyDecision {
        return DB::transaction(function () use ($actor, $alertId, $reauthenticationToken, $idempotencyKey, $reason): DelinquencyDecision {
            $payload = ['alert_id' => $alertId, 'reason' => $reason];
            $replay = $this->idempotency->replayedResource($actor, 'APPLY_DELINQUENCY', $idempotencyKey, $payload);
            if ($replay !== null) {
                $decision = DelinquencyDecision::query()->findOrFail($replay);
                $decision->setAttribute('_idempotency_replayed', true);

                return $decision;
            }
            $alert = RiskAlert::query()->where('alert_number', $alertId)->lockForUpdate()->firstOrFail();
            $distributor = User::query()->with('role')->lockForUpdate()->findOrFail($alert->distributor_id);
            $profile = DistributorRiskProfile::query()->where('distributor_id', $distributor->id)->lockForUpdate()->firstOrFail();
            $this->scope->assertManagerScope($actor, $distributor);
            $permission = $actor->role_code === RoleCode::GENERAL_MANAGER->value
                ? PermissionCode::DELINQUENCY_APPLY_GLOBAL
                : PermissionCode::DELINQUENCY_APPLY_BRANCH;
            if (! $this->hasPermission($actor, $permission)) {
                throw RiskDelinquencyException::scopeDenied(false);
            }
            if ($alert->alert_type !== RiskAlertType::THIRD_BREACH
                || $alert->status !== RiskAlertStatus::ACTIVE
                || $profile->consecutive_breaches < 3
                || $profile->profile_status !== RiskProfileStatus::CURRENT
                || bccomp($profile->overdue_balance, '0', 4) <= 0) {
                throw RiskDelinquencyException::stateConflict('THREE_BREACHES_NOT_CONFIRMED');
            }
            if ($profile->delinquency_status !== DelinquencyStatus::NOT_DELINQUENT) {
                throw RiskDelinquencyException::stateConflict('DISTRIBUTOR_ALREADY_DELINQUENT');
            }
            if ($alert->relations()->count() !== 3) {
                throw RiskDelinquencyException::stateConflict('RISK_ALERT_NOT_ACTIONABLE');
            }
            $authorizationId = $this->reauthentication->consume(
                $actor,
                $reauthenticationToken,
                CriticalAction::DELINQUENCY_APPLY,
                RiskAlert::class,
                $alert->alert_number,
                $actor->branch_public_id,
                [],
            );
            $before = [
                'state' => $profile->delinquency_status->value,
                'blocked_for_new_vouchers' => $profile->blocked_for_new_vouchers,
                'lock_version' => $profile->lock_version,
            ];
            $after = [
                'state' => DelinquencyStatus::DELINQUENT->value,
                'blocked_for_new_vouchers' => true,
                'lock_version' => $profile->lock_version + 1,
            ];
            $decision = DelinquencyDecision::query()->create([
                'decision_number' => (string) Str::uuid(),
                'distributor_id' => $distributor->id,
                'risk_alert_id' => $alert->id,
                'branch_id' => $alert->branch_id,
                'decision' => 'APPLIED',
                'decided_by' => $actor->id,
                'decided_role' => (string) $actor->role_code,
                'reauthentication_id' => $authorizationId,
                'overdue_balance_snapshot' => $profile->overdue_balance,
                'reason' => $reason,
                'before_snapshot' => $before,
                'after_snapshot' => $after,
                'decided_at' => $this->clock->nowUtc(),
                'idempotency_key' => hash('sha256', $actor->id.'|'.$idempotencyKey),
                'created_at' => $this->clock->nowUtc(),
            ]);
            $profile->forceFill([
                'delinquency_status' => DelinquencyStatus::DELINQUENT,
                'blocked_for_new_vouchers' => true,
                'delinquency_applied_at' => $this->clock->nowUtc(),
                'lock_version' => $profile->lock_version + 1,
            ])->save();
            $alert->forceFill([
                'status' => RiskAlertStatus::RESOLVED_BY_DECISION,
                'resolved_at' => $this->clock->nowUtc(),
            ])->save();
            $this->recorder->record(
                'DistributorDelinquencyApplied',
                'delinquency_decision',
                $decision->id,
                $distributor->id,
                $alert->branch_id,
                $actor,
                $before,
                $after,
                reason: $reason,
                idempotencyKey: $idempotencyKey,
            );
            $this->recorder->outbox('DistributorDelinquencyApplied', $decision->id, $decision->id, [
                'decision_id' => $decision->decision_number,
                'distributor_id' => $distributor->public_id,
            ]);
            $this->recorder->outbox('DistributorVoucherIssuanceBlocked', $decision->id.':block', $decision->id, [
                'distributor_id' => $distributor->public_id,
                'restriction_code' => 'DELINQUENCY',
            ]);
            $this->idempotency->complete($actor, 'APPLY_DELINQUENCY', $idempotencyKey, $decision->id, 201);
            $decision->setAttribute('_idempotency_replayed', false);

            return $decision;
        }, 3);
    }

    private function hasPermission(User $actor, PermissionCode $permission): bool
    {
        return $actor->role()->whereHas(
            'permissions',
            fn ($query) => $query->where('permissions.code', $permission->value)->where('permissions.is_active', true),
        )->exists();
    }
}
