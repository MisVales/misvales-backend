<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Credit\Application\Contracts\CreditRecoveryGateway;
use App\Modules\Credit\Application\Contracts\CreditVoucherGateway;
use App\Modules\Credit\Application\DTOs\CapitalRecovery;
use App\Modules\Credit\Application\DTOs\CreditEligibility;
use App\Modules\Credit\Application\DTOs\VoucherCapitalUsage;
use App\Modules\Credit\Domain\Aggregates\CreditLine;
use App\Modules\Credit\Domain\Enums\CreditMovementType;
use App\Modules\Credit\Domain\Enums\IncreaseRequestStatus;
use App\Modules\Credit\Domain\Enums\RestrictionStatus;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\Repositories\CreditLineRepository;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Mappers\CreditLineMapper;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineMovementModel;
use Illuminate\Support\Facades\DB;

final readonly class CreditLineOperationsService implements CreditRecoveryGateway, CreditVoucherGateway
{
    public function __construct(
        private CreditLineRepository $lines,
        private CreditLineMapper $mapper,
        private CreditMovementService $movements,
        private CreditRestrictionService $restrictions,
        private CreditRecorder $recorder,
    ) {}

    public function eligibility(int $distributorId, Money $capital): CreditEligibility
    {
        $this->assertActiveDistributor($distributorId, false);
        $line = $this->lines->findForDistributor($distributorId);
        if ($line === null) {
            throw new CreditRuleViolation('No existe línea para la distribuidora.', 'CREDIT_LINE_NOT_FOUND', 404);
        }

        $domain = $this->mapper->toDomain($line);
        if ($capital->greaterThan($domain->availableBalance()) || ! $capital->isPositive()) {
            return new CreditEligibility(false, $domain->availableBalance(), null, null);
        }

        $restriction = $this->restrictions->activeForLine($line->id);
        if ($restriction === null) {
            return new CreditEligibility(true, $domain->availableBalance(), null, null);
        }

        $range = $this->restrictions->range($restriction, $domain->availableBalance());

        return new CreditEligibility($range->admits($capital), $domain->availableBalance(), $range, $restriction->public_id);
    }

    public function bindRestriction(int $distributorId, string $voucherId, Money $capital, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($distributorId, $voucherId, $capital, $actorUserId): void {
            $line = $this->requiredLockedLine($distributorId);
            $restriction = $this->restrictions->activeForLine($line->id, true);
            if ($restriction === null) {
                return;
            }
            if ($restriction->status === RestrictionStatus::BOUND) {
                if ($restriction->bound_voucher_id === $voucherId) {
                    return;
                }
                throw new CreditRuleViolation('La restricción ya está vinculada a otro vale.', 'CREDIT_RESTRICTION_ALREADY_BOUND');
            }

            $this->restrictions->assertCapital($restriction, new Money($line->available_balance), $capital);
            $restriction->forceFill([
                'status' => RestrictionStatus::BOUND,
                'bound_voucher_id' => $voucherId,
                'bound_at' => now('UTC'),
            ])->save();
            $actor = $actorUserId === null ? null : User::query()->find($actorUserId);
            $this->recorder->audit(
                'CREDIT_RESTRICTION_BOUND',
                'SUCCESS',
                $actor,
                $distributorId,
                $line->distributor()->value('branch_id'),
                'credit_usage_restrictions',
                $restriction->public_id,
                ['status' => RestrictionStatus::ACTIVE->value],
                ['status' => RestrictionStatus::BOUND->value, 'voucher_id' => $voucherId],
                metadata: ['capital' => $capital->format()],
            );
        }, 3);
    }

    public function releaseRestriction(int $distributorId, string $voucherId, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($distributorId, $voucherId, $actorUserId): void {
            $line = $this->requiredLockedLine($distributorId);
            $restriction = $this->restrictions->activeForLine($line->id, true);
            if ($restriction === null || $restriction->status === RestrictionStatus::ACTIVE) {
                return;
            }
            if ($restriction->status !== RestrictionStatus::BOUND || $restriction->bound_voucher_id !== $voucherId) {
                throw new CreditRuleViolation('El vale no corresponde a la restricción vinculada.', 'CREDIT_RESTRICTION_VOUCHER_MISMATCH');
            }
            $restriction->forceFill([
                'status' => RestrictionStatus::ACTIVE,
                'bound_voucher_id' => null,
                'bound_at' => null,
            ])->save();
            $actor = $actorUserId === null ? null : User::query()->find($actorUserId);
            $this->recorder->audit(
                'CREDIT_RESTRICTION_RELEASED',
                'SUCCESS',
                $actor,
                $distributorId,
                $line->distributor()->value('branch_id'),
                'credit_usage_restrictions',
                $restriction->public_id,
                ['status' => RestrictionStatus::BOUND->value, 'voucher_id' => $voucherId],
                ['status' => RestrictionStatus::ACTIVE->value],
            );
        }, 3);
    }

    public function applyFulfilledVoucher(VoucherCapitalUsage $usage): string
    {
        return DB::transaction(function () use ($usage): string {
            $line = $this->requiredLockedLine($usage->distributorId);
            $duplicate = CreditLineMovementModel::query()
                ->where(fn ($query) => $query
                    ->where('idempotency_key', $usage->idempotencyKey)
                    ->orWhere(fn ($nested) => $nested
                        ->where('source_type', 'VOUCHER')
                        ->where('source_id', $usage->voucherId)
                        ->where('type', CreditMovementType::VOUCHER_FULFILLED->value)))
                ->first();
            if ($duplicate !== null) {
                $this->recorder->audit(
                    'CREDIT_DUPLICATE_ATTEMPT',
                    'IDEMPOTENT_REPLAY',
                    $usage->actorUserId === null ? null : User::query()->find($usage->actorUserId),
                    $usage->distributorId,
                    $usage->branchId,
                    'credit_line_movements',
                    $duplicate->public_id,
                    reason: $usage->reason,
                    metadata: ['operation' => CreditMovementType::VOUCHER_FULFILLED->value],
                    idempotencyKey: $usage->idempotencyKey,
                );

                return $duplicate->public_id;
            }

            $this->assertActiveDistributor($usage->distributorId);
            $restriction = $this->restrictions->activeForLine($line->id, true);
            if ($restriction !== null) {
                if ($restriction->status !== RestrictionStatus::BOUND) {
                    throw new CreditRuleViolation(
                        'La restricción debe vincularse antes de feriar el vale.',
                        'CREDIT_RESTRICTION_NOT_ACTIVE',
                    );
                }
                if ($restriction->bound_voucher_id !== $usage->voucherId) {
                    throw new CreditRuleViolation('El vale no corresponde a la restricción vinculada.', 'CREDIT_RESTRICTION_VOUCHER_MISMATCH');
                }
                $this->restrictions->assertCapital($restriction, new Money($line->available_balance), $usage->capital);
            }

            $before = $this->mapper->toDomain($line);
            $after = $before->useCapital($usage->capital);
            $actor = $usage->actorUserId === null ? null : User::query()->find($usage->actorUserId);
            $movement = $this->movements->append(
                $line,
                $before,
                $after,
                CreditMovementType::VOUCHER_FULFILLED,
                'VOUCHER',
                $usage->voucherId,
                $actor,
                null,
                $usage->branchId,
                $usage->reason,
                $usage->idempotencyKey,
            );

            if ($restriction !== null) {
                $restriction->forceFill([
                    'status' => RestrictionStatus::CONSUMED,
                    'bound_voucher_id' => $usage->voucherId,
                    'bound_at' => $restriction->bound_at ?? now('UTC'),
                    'consumed_by_voucher_id' => $usage->voucherId,
                    'consumed_at' => now('UTC'),
                ])->save();
                CreditIncreaseRequestModel::query()
                    ->where('restriction_id', $restriction->id)
                    ->where('status', IncreaseRequestStatus::FIFTY_PERCENT_RESTRICTION_ACTIVE->value)
                    ->update([
                        'status' => IncreaseRequestStatus::COMPLETED->value,
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_at' => now('UTC'),
                    ]);
                $this->recorder->event('FiftyPercentRestrictionConsumed', "restriction-consumed:{$restriction->public_id}", [
                    'distributor_id' => $usage->distributorId,
                    'branch_id' => $usage->branchId,
                    'voucher_id' => $usage->voucherId,
                    'restriction_id' => $restriction->public_id,
                    'amount' => $usage->capital->format(),
                    'occurred_at' => now('UTC')->toIso8601String(),
                ]);
                $this->recorder->event('CreditRestrictionConsumed', "credit-restriction-consumed:{$restriction->public_id}", [
                    'distributor_id' => $usage->distributorId,
                    'branch_id' => $usage->branchId,
                    'voucher_id' => $usage->voucherId,
                    'restriction_id' => $restriction->public_id,
                    'amount' => $usage->capital->format(),
                    'occurred_at' => now('UTC')->toIso8601String(),
                ]);
            }

            $this->recorder->audit(
                'CREDIT_CAPITAL_USED',
                'SUCCESS',
                $actor,
                $usage->distributorId,
                $usage->branchId,
                'credit_line_movements',
                $movement->public_id,
                $this->snapshot($before),
                $this->snapshot($after),
                $usage->reason,
                ['voucher_id' => $usage->voucherId, 'restriction_id' => $restriction?->public_id],
                $usage->idempotencyKey,
            );
            $this->recorder->event('CreditCapitalUsed', "credit-capital-used:{$usage->idempotencyKey}", [
                'distributor_id' => $usage->distributorId,
                'branch_id' => $usage->branchId,
                'actor_id' => $actor?->public_id,
                'amount' => $usage->capital->format(),
                'movement_id' => $movement->public_id,
                'balances_before' => $this->snapshot($before),
                'balances_after' => $this->snapshot($after),
                'occurred_at' => now('UTC')->toIso8601String(),
            ]);

            return $movement->public_id;
        }, 3);
    }

    public function recover(CapitalRecovery $recovery): Money
    {
        return DB::transaction(function () use ($recovery): Money {
            if (! $recovery->isReconciled) {
                throw new CreditRuleViolation('Solo un origen conciliado puede recuperar línea.', 'CREDIT_INVALID_BALANCE', 422);
            }
            $line = $this->requiredLockedLine($recovery->distributorId);
            $duplicate = CreditLineMovementModel::query()
                ->where(fn ($query) => $query
                    ->where('idempotency_key', $recovery->idempotencyKey)
                    ->orWhere(fn ($nested) => $nested
                        ->where('source_type', 'RECONCILED_PAYMENT')
                        ->where('source_id', $recovery->sourceId)
                        ->where('type', CreditMovementType::CAPITAL_RECOVERED->value)))
                ->first();
            if ($duplicate !== null) {
                $this->recorder->audit(
                    'CREDIT_DUPLICATE_ATTEMPT',
                    'IDEMPOTENT_REPLAY',
                    $recovery->actorUserId === null ? null : User::query()->find($recovery->actorUserId),
                    $recovery->distributorId,
                    $recovery->branchId,
                    'credit_line_movements',
                    $duplicate->public_id,
                    reason: $recovery->reason,
                    metadata: ['operation' => CreditMovementType::CAPITAL_RECOVERED->value],
                    idempotencyKey: $recovery->idempotencyKey,
                    authorizerId: $recovery->authorizedByUserId,
                );

                return new Money(ltrim((string) $duplicate->used_delta, '-'));
            }

            $before = $this->mapper->toDomain($line);
            [$after, $applied] = $before->recoverCapital($recovery->capital);
            $actor = $recovery->actorUserId === null ? null : User::query()->find($recovery->actorUserId);
            $movement = $this->movements->append(
                $line,
                $before,
                $after,
                CreditMovementType::CAPITAL_RECOVERED,
                'RECONCILED_PAYMENT',
                $recovery->sourceId,
                $actor,
                $recovery->authorizedByUserId,
                $recovery->branchId,
                $recovery->reason,
                $recovery->idempotencyKey,
            );
            $this->recorder->audit(
                'CREDIT_CAPITAL_RECOVERED',
                'SUCCESS',
                $actor,
                $recovery->distributorId,
                $recovery->branchId,
                'credit_line_movements',
                $movement->public_id,
                $this->snapshot($before),
                $this->snapshot($after),
                $recovery->reason,
                ['source_id' => $recovery->sourceId, 'requested_amount' => $recovery->capital->format(), 'applied_amount' => $applied->format()],
                $recovery->idempotencyKey,
                authorizerId: $recovery->authorizedByUserId,
            );
            $this->recorder->event('CreditCapitalRecovered', "credit-capital-recovered:{$recovery->idempotencyKey}", [
                'distributor_id' => $recovery->distributorId,
                'branch_id' => $recovery->branchId,
                'amount' => $applied->format(),
                'movement_id' => $movement->public_id,
                'balances_before' => $this->snapshot($before),
                'balances_after' => $this->snapshot($after),
                'occurred_at' => now('UTC')->toIso8601String(),
            ]);

            return $applied;
        }, 3);
    }

    private function requiredLockedLine(int $distributorId): CreditLineModel
    {
        $line = $this->lines->lockForDistributor($distributorId);
        if ($line === null) {
            throw new CreditRuleViolation('No existe línea para la distribuidora.', 'CREDIT_LINE_NOT_FOUND', 404);
        }

        return $line;
    }

    private function assertActiveDistributor(int $distributorId, bool $lock = true): void
    {
        $query = User::query()->whereKey($distributorId);
        $distributor = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($distributor === null || $distributor->state !== AccountState::ACTIVE) {
            throw new CreditRuleViolation('La distribuidora no se encuentra activa.', 'AUTH_SCOPE_DENIED', 403);
        }
    }

    /** @return array<string, string> */
    private function snapshot(CreditLine $line): array
    {
        return [
            'total_authorized' => $line->totalAuthorized->format(),
            'used_balance' => $line->usedBalance->format(),
            'available_balance' => $line->availableBalance()->format(),
            'recovered_capital_total' => $line->recoveredCapitalTotal->format(),
        ];
    }
}
