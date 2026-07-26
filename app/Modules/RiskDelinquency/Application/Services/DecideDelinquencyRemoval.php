<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\RiskDelinquency\Application\Contracts\OrganizationScopePort;
use App\Modules\RiskDelinquency\Application\Contracts\OverdueBalancePort;
use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use App\Modules\RiskDelinquency\Application\Contracts\RiskReauthenticationPort;
use App\Modules\RiskDelinquency\Domain\Enums\DelinquencyStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RemovalRequestStatus;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Domain\ValueObjects\OverdueBalance;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyRemovalRequest;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use Illuminate\Support\Facades\DB;

final class DecideDelinquencyRemoval
{
    public function __construct(
        private readonly OrganizationScopePort $scope,
        private readonly OverdueBalancePort $balances,
        private readonly RiskReauthenticationPort $reauthentication,
        private readonly RiskClock $clock,
        private readonly RiskHttpIdempotency $idempotency,
        private readonly RiskRecorder $recorder,
    ) {}

    public function approve(User $actor, string $requestNumber, string $token, string $key, ?string $reason): DelinquencyRemovalRequest
    {
        return $this->decide($actor, $requestNumber, RemovalRequestStatus::APPROVED, $token, $key, $reason);
    }

    public function reject(User $actor, string $requestNumber, string $token, string $key, ?string $reason): DelinquencyRemovalRequest
    {
        return $this->decide($actor, $requestNumber, RemovalRequestStatus::REJECTED, $token, $key, $reason);
    }

    private function decide(
        User $actor,
        string $requestNumber,
        RemovalRequestStatus $decision,
        string $token,
        string $key,
        ?string $reason,
    ): DelinquencyRemovalRequest {
        $operation = $decision === RemovalRequestStatus::APPROVED ? 'APPROVE_REMOVAL' : 'REJECT_REMOVAL';

        return DB::transaction(function () use ($actor, $requestNumber, $decision, $token, $key, $reason, $operation): DelinquencyRemovalRequest {
            $payload = ['request_id' => $requestNumber, 'decision' => $decision->value, 'reason' => $reason];
            $replay = $this->idempotency->replayedResource($actor, $operation, $key, $payload);
            if ($replay !== null) {
                $request = DelinquencyRemovalRequest::query()->findOrFail($replay);
                $request->setAttribute('_idempotency_replayed', true);

                return $request;
            }
            $request = DelinquencyRemovalRequest::query()->where('request_number', $requestNumber)->lockForUpdate()->firstOrFail();
            $distributor = User::query()->lockForUpdate()->findOrFail($request->distributor_id);
            $profile = DistributorRiskProfile::query()->where('distributor_id', $distributor->id)->lockForUpdate()->firstOrFail();
            $this->scope->assertManagerScope($actor, $distributor);
            $permission = $actor->role_code === RoleCode::GENERAL_MANAGER->value
                ? PermissionCode::DELINQUENCY_REMOVAL_DECIDE_GLOBAL
                : PermissionCode::DELINQUENCY_REMOVAL_DECIDE_BRANCH;
            if (! $this->hasPermission($actor, $permission)) {
                throw RiskDelinquencyException::scopeDenied(false);
            }
            if ($request->status !== RemovalRequestStatus::PREPARED) {
                throw RiskDelinquencyException::stateConflict(
                    $request->status === RemovalRequestStatus::INVALIDATED
                        ? 'REMOVAL_REQUEST_INVALIDATED'
                        : 'REMOVAL_REQUEST_NOT_PREPARED',
                );
            }
            if ($profile->delinquency_status !== DelinquencyStatus::REGULARIZED_PENDING_REMOVAL) {
                throw RiskDelinquencyException::stateConflict('DISTRIBUTOR_NOT_DELINQUENT');
            }
            if ($decision === RemovalRequestStatus::APPROVED
                && ! (new OverdueBalance($this->balances->totalForDistributor($distributor->id)))->isZero()) {
                throw new RiskDelinquencyException('OVERDUE_BALANCE_NOT_ZERO', 'El saldo vencido debe permanecer en cero.', 409);
            }
            $authorizationId = $this->reauthentication->consume(
                $actor,
                $token,
                CriticalAction::DELINQUENCY_REMOVE,
                DelinquencyRemovalRequest::class,
                $request->request_number,
                $actor->branch_public_id,
                ['decision' => $decision->value],
            );
            $before = [
                'state' => $profile->delinquency_status->value,
                'blocked_for_new_vouchers' => $profile->blocked_for_new_vouchers,
                'request_status' => $request->status->value,
            ];
            $request->forceFill([
                'status' => $decision,
                'decided_by' => $actor->id,
                'decided_role' => (string) $actor->role_code,
                'decision_reason' => $reason,
                'reauthentication_id' => $authorizationId,
                'decided_at' => $this->clock->nowUtc(),
                'lock_version' => $request->lock_version + 1,
            ])->save();
            if ($decision === RemovalRequestStatus::APPROVED) {
                $profile->forceFill([
                    'delinquency_status' => DelinquencyStatus::NOT_DELINQUENT,
                    'blocked_for_new_vouchers' => false,
                    'delinquency_applied_at' => null,
                    'lock_version' => $profile->lock_version + 1,
                ])->save();
            }
            $after = [
                'state' => $profile->delinquency_status->value,
                'blocked_for_new_vouchers' => $profile->blocked_for_new_vouchers,
                'request_status' => $request->status->value,
            ];
            $event = $decision === RemovalRequestStatus::APPROVED
                ? 'DistributorDelinquencyRemoved'
                : 'DelinquencyRemovalRejected';
            $this->recorder->record(
                $event,
                'removal_request',
                $request->id,
                $distributor->id,
                $request->branch_id,
                $actor,
                $before,
                $after,
                reason: $reason,
                idempotencyKey: $key,
            );
            $this->recorder->outbox($event, $request->id.':'.$decision->value, $request->id, [
                'request_id' => $request->request_number,
                'distributor_id' => $distributor->public_id,
            ]);
            if ($decision === RemovalRequestStatus::APPROVED) {
                $this->recorder->outbox('DistributorVoucherIssuanceUnblocked', $request->id.':unblock', $request->id, [
                    'distributor_id' => $distributor->public_id,
                ]);
            }
            $this->idempotency->complete($actor, $operation, $key, $request->id, 201);
            $request->setAttribute('_idempotency_replayed', false);

            return $request;
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
