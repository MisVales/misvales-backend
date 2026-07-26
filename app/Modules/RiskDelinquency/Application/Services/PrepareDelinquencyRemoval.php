<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\RiskDelinquency\Application\Contracts\OrganizationScopePort;
use App\Modules\RiskDelinquency\Application\Contracts\OverdueBalancePort;
use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use App\Modules\RiskDelinquency\Domain\Enums\DelinquencyStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RemovalRequestStatus;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Domain\ValueObjects\OverdueBalance;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyDecision;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyRemovalRequest;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PrepareDelinquencyRemoval
{
    public function __construct(
        private readonly OrganizationScopePort $scope,
        private readonly OverdueBalancePort $balances,
        private readonly RiskClock $clock,
        private readonly RiskHttpIdempotency $idempotency,
        private readonly RiskRecorder $recorder,
    ) {}

    public function prepare(User $actor, User $distributor, string $idempotencyKey, ?string $reason): DelinquencyRemovalRequest
    {
        return DB::transaction(function () use ($actor, $distributor, $idempotencyKey, $reason): DelinquencyRemovalRequest {
            $payload = ['distributor_id' => $distributor->public_id, 'reason' => $reason];
            $replay = $this->idempotency->replayedResource($actor, 'PREPARE_REMOVAL', $idempotencyKey, $payload);
            if ($replay !== null) {
                $request = DelinquencyRemovalRequest::query()->findOrFail($replay);
                $request->setAttribute('_idempotency_replayed', true);

                return $request;
            }
            $lockedDistributor = User::query()->lockForUpdate()->findOrFail($distributor->id);
            $this->scope->assertResponsibleCoordinator($actor, $lockedDistributor);
            if (! $this->hasPermission($actor, PermissionCode::DELINQUENCY_REMOVAL_PREPARE)) {
                throw RiskDelinquencyException::scopeDenied(false);
            }
            $profile = DistributorRiskProfile::query()
                ->where('distributor_id', $lockedDistributor->id)
                ->lockForUpdate()
                ->firstOrFail();
            $balance = new OverdueBalance($this->balances->totalForDistributor($lockedDistributor->id));
            if (! $balance->isZero()
                || $profile->delinquency_status !== DelinquencyStatus::REGULARIZED_PENDING_REMOVAL
                || $profile->financially_regularized_at === null) {
                throw RiskDelinquencyException::stateConflict('DISTRIBUTOR_NOT_FINANCIALLY_REGULARIZED');
            }
            if (DelinquencyRemovalRequest::query()
                ->where('distributor_id', $lockedDistributor->id)
                ->where('status', RemovalRequestStatus::PREPARED->value)
                ->lockForUpdate()
                ->exists()) {
                throw RiskDelinquencyException::stateConflict('REMOVAL_REQUEST_ALREADY_ACTIVE');
            }
            $decision = DelinquencyDecision::query()
                ->where('distributor_id', $lockedDistributor->id)
                ->latest('decided_at')
                ->firstOrFail();
            $request = DelinquencyRemovalRequest::query()->create([
                'request_number' => (string) Str::uuid(),
                'distributor_id' => $lockedDistributor->id,
                'branch_id' => $profile->current_branch_id,
                'coordinator_id' => $actor->id,
                'delinquency_decision_id' => $decision->id,
                'status' => RemovalRequestStatus::PREPARED,
                'overdue_balance_snapshot' => '0.0000',
                'prepared_reason' => $reason,
                'prepared_at' => $this->clock->nowUtc(),
                'lock_version' => 1,
                'idempotency_key' => hash('sha256', $actor->id.'|'.$idempotencyKey),
            ]);
            $this->recorder->record(
                'DelinquencyRemovalPrepared',
                'removal_request',
                $request->id,
                $lockedDistributor->id,
                $request->branch_id,
                $actor,
                after: ['state' => RemovalRequestStatus::PREPARED->value, 'overdue_balance' => '0.0000'],
                reason: $reason,
                idempotencyKey: $idempotencyKey,
            );
            $this->recorder->outbox('DelinquencyRemovalPrepared', $request->id, $request->id, [
                'request_id' => $request->request_number,
                'distributor_id' => $lockedDistributor->public_id,
            ]);
            $this->idempotency->complete($actor, 'PREPARE_REMOVAL', $idempotencyKey, $request->id, 201);
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
