<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Services;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Configuration\Application\Contracts\ConfigurationReadContract;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Points\Domain\Enums\PointRedemptionStatus;
use App\Modules\Points\Domain\Enums\PointReservationStatus;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Domain\Services\RedemptionAmountCalculator;
use App\Modules\Points\Infrastructure\Persistence\Models\PointAccountModel;
use App\Modules\Points\Infrastructure\Persistence\Models\PointRedemptionRequestModel;
use App\Modules\Points\Infrastructure\Persistence\Models\PointReservationModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DecidePointRedemption
{
    public function __construct(
        private PointsAccessService $access,
        private TemporaryAuthorization $reauthentication,
        private ConfigurationReadContract $configuration,
        private RedemptionAmountCalculator $amountCalculator,
        private PointRecorder $recorder,
        private PointHttpIdempotency $idempotency,
    ) {}

    public function authorize(
        User $actor,
        string $requestId,
        string $reauthenticationToken,
        string $idempotencyKey,
        ?string $reason,
    ): PointRedemptionRequestModel {
        return DB::transaction(function () use ($actor, $requestId, $reauthenticationToken, $idempotencyKey, $reason): PointRedemptionRequestModel {
            $claim = $this->idempotency->claim($actor, 'point-redemptions.authorize', $idempotencyKey, [
                'request_id' => $requestId,
                'reason' => $reason,
            ]);
            if ($claim['replayed'] && $claim['resource_id'] !== null) {
                return PointRedemptionRequestModel::query()->whereKey($claim['resource_id'])->firstOrFail();
            }

            [$request, $reservation, $account, $distributor] = $this->lockDecisionResources($requestId);
            $this->access->assertCanDecide($actor, $distributor);
            if ($request->status !== PointRedemptionStatus::PENDING) {
                throw new PointsDomainException(
                    'REDEMPTION_REQUEST_NOT_PENDING',
                    'La solicitud ya no está pendiente.',
                    409,
                );
            }
            $this->assertReservation($request, $reservation, $account);
            $this->reauthentication->consumeReauth(
                $actor,
                $reauthenticationToken,
                CriticalAction::POINT_REDEMPTION_AUTHORIZE->value,
                $request->id,
            );

            $value = $this->configuration->resolve(ConfigurationKey::POINT_VALUE_AMOUNT, now('UTC')->toImmutable());
            $cashAmount = $this->amountCalculator->calculate((int) $request->requested_points, $value->value);
            $request->forceFill([
                'status' => PointRedemptionStatus::AUTHORIZED,
                'authorized_points' => (int) $request->requested_points,
                'point_value_snapshot' => $value->value,
                'point_value_version_id' => $value->versionPublicId,
                'cash_amount' => $cashAmount,
                'value_frozen_at' => now('UTC'),
                'decided_at' => now('UTC'),
                'authorized_by' => $actor->id,
                'decision_reason' => $reason,
            ])->save();
            $this->history($request, PointRedemptionStatus::PENDING, $actor, $idempotencyKey, $reason);
            $this->recordDecision($request, $actor, 'POINT_REDEMPTION_AUTHORIZED', 'PointRedemptionAuthorized', $idempotencyKey);
            $this->idempotency->complete($actor, 'point-redemptions.authorize', $idempotencyKey, $request->id);

            return $request;
        });
    }

    public function reject(
        User $actor,
        string $requestId,
        string $reauthenticationToken,
        string $idempotencyKey,
        ?string $reason,
    ): PointRedemptionRequestModel {
        return DB::transaction(function () use ($actor, $requestId, $reauthenticationToken, $idempotencyKey, $reason): PointRedemptionRequestModel {
            $claim = $this->idempotency->claim($actor, 'point-redemptions.reject', $idempotencyKey, [
                'request_id' => $requestId,
                'reason' => $reason,
            ]);
            if ($claim['replayed'] && $claim['resource_id'] !== null) {
                return PointRedemptionRequestModel::query()->whereKey($claim['resource_id'])->firstOrFail();
            }

            [$request, $reservation, $account, $distributor] = $this->lockDecisionResources($requestId);
            $this->access->assertCanDecide($actor, $distributor);
            if ($request->status !== PointRedemptionStatus::PENDING) {
                throw new PointsDomainException(
                    'REDEMPTION_REQUEST_NOT_PENDING',
                    'La solicitud ya no está pendiente.',
                    409,
                );
            }
            $this->assertReservation($request, $reservation, $account);
            $this->reauthentication->consumeReauth(
                $actor,
                $reauthenticationToken,
                CriticalAction::POINT_REDEMPTION_REJECT->value,
                $request->id,
            );

            $reservedBefore = (int) $account->reserved_points;
            $account->forceFill([
                'reserved_points' => $reservedBefore - (int) $reservation->points,
                'lock_version' => (int) $account->lock_version + 1,
            ])->save();
            $reservation->forceFill([
                'status' => PointReservationStatus::RELEASED,
                'released_at' => now('UTC'),
            ])->save();
            $request->forceFill([
                'status' => PointRedemptionStatus::REJECTED,
                'decided_at' => now('UTC'),
                'rejected_by' => $actor->id,
                'decision_reason' => $reason,
            ])->save();
            $this->history($request, PointRedemptionStatus::PENDING, $actor, $idempotencyKey, $reason);
            $this->recordDecision($request, $actor, 'POINT_REDEMPTION_REJECTED', 'PointRedemptionRejected', $idempotencyKey);
            $this->recorder->outbox('PointReservationReleased', 'reservation-released:'.$reservation->id, [
                'distributor_id' => (string) $distributor->public_id,
                'branch_id' => (string) $request->branchSnapshot->public_id,
                'redemption_request_id' => $request->id,
                'points' => (int) $reservation->points,
                'balance_before' => (int) $account->total_points,
                'balance_after' => (int) $account->total_points,
                'actor' => (string) $actor->public_id,
            ]);
            $this->idempotency->complete($actor, 'point-redemptions.reject', $idempotencyKey, $request->id);

            return $request;
        });
    }

    /** @return array{PointRedemptionRequestModel, PointReservationModel, PointAccountModel, User} */
    private function lockDecisionResources(string $requestId): array
    {
        $request = PointRedemptionRequestModel::query()->whereKey($requestId)->lockForUpdate()->first();
        if ($request === null) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'La solicitud no existe.', 404);
        }
        $account = PointAccountModel::query()->whereKey($request->point_account_id)->lockForUpdate()->firstOrFail();
        $reservation = PointReservationModel::query()
            ->where('redemption_request_id', $request->id)
            ->lockForUpdate()
            ->first();
        $distributor = User::query()->with('role')->findOrFail($request->distributor_id);
        if ($reservation === null) {
            throw new PointsDomainException(
                'REDEMPTION_RESERVATION_MISSING',
                'La reserva de la solicitud no existe.',
                409,
            );
        }

        return [$request, $reservation, $account, $distributor];
    }

    private function assertReservation(
        PointRedemptionRequestModel $request,
        PointReservationModel $reservation,
        PointAccountModel $account,
    ): void {
        if ($reservation->status !== PointReservationStatus::ACTIVE
            || (int) $reservation->points !== (int) $request->requested_points
            || (int) $account->reserved_points < (int) $reservation->points) {
            throw new PointsDomainException(
                'REDEMPTION_RESERVATION_INCONSISTENT',
                'La reserva ya no respalda íntegramente la solicitud.',
                409,
            );
        }
    }

    private function history(
        PointRedemptionRequestModel $request,
        PointRedemptionStatus $from,
        User $actor,
        string $idempotencyKey,
        ?string $reason,
    ): void {
        DB::table('point_redemption_status_history')->insert([
            'id' => (string) Str::uuid(),
            'redemption_request_id' => $request->id,
            'from_status' => $from->value,
            'to_status' => $request->status->value,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role_code,
            'branch_id_snapshot' => $actor->branch_id,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now('UTC'),
            'security_context' => json_encode([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'device' => request()->header('X-Device-Id'),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function recordDecision(
        PointRedemptionRequestModel $request,
        User $actor,
        string $auditEvent,
        string $domainEvent,
        string $idempotencyKey,
    ): void {
        $request->loadMissing(['distributor', 'branchSnapshot']);
        $this->recorder->audit(
            $auditEvent,
            'SUCCESS',
            'point_redemption_requests',
            $request->id,
            $actor,
            (int) $request->distributor_id,
            (int) $request->branch_id_snapshot,
            before: ['status' => PointRedemptionStatus::PENDING->value],
            after: ['status' => $request->status->value],
            idempotencyKey: $idempotencyKey,
            reason: $request->decision_reason,
        );
        $this->recorder->outbox($domainEvent, strtolower($domainEvent).':'.$request->id, [
            'distributor_id' => (string) $request->distributor->public_id,
            'branch_id' => (string) $request->branchSnapshot->public_id,
            'redemption_request_id' => $request->id,
            'points' => (int) ($request->authorized_points ?? $request->requested_points),
            'amount' => $request->cash_amount,
            'actor' => (string) $actor->public_id,
        ]);
    }
}
