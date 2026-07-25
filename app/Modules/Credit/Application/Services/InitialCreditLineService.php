<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\Credit\Application\DTOs\InitialCreditAuthorization;
use App\Modules\Credit\Domain\Aggregates\CreditLine;
use App\Modules\Credit\Domain\Enums\CreditMovementType;
use App\Modules\Credit\Domain\Enums\RestrictionTriggerType;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\Repositories\CreditLineRepository;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;
use Illuminate\Support\Facades\DB;

final readonly class InitialCreditLineService
{
    public function __construct(
        private CreditLineRepository $lines,
        private CreditMovementService $movements,
        private CreditRestrictionService $restrictions,
        private CreditRecorder $recorder,
    ) {}

    public function register(InitialCreditAuthorization $authorization): CreditLineModel
    {
        return DB::transaction(function () use ($authorization): CreditLineModel {
            $existing = $this->lines->lockForDistributor($authorization->distributorId);
            if ($existing !== null) {
                $movement = $existing->movements()
                    ->where('type', CreditMovementType::INITIAL_AUTHORIZATION->value)
                    ->where('source_id', $authorization->authorizationId)
                    ->first();
                if ($movement !== null) {
                    $this->recorder->audit(
                        'CREDIT_DUPLICATE_ATTEMPT',
                        'IDEMPOTENT_REPLAY',
                        User::query()->find($authorization->authorizedByUserId),
                        $authorization->distributorId,
                        $authorization->branchId,
                        'credit_lines',
                        $existing->public_id,
                        reason: $authorization->reason,
                        metadata: ['operation' => CreditMovementType::INITIAL_AUTHORIZATION->value],
                        idempotencyKey: $authorization->idempotencyKey,
                    );

                    return $existing;
                }

                throw new CreditRuleViolation('La distribuidora ya tiene una línea de crédito.', 'CREDIT_LINE_ALREADY_EXISTS');
            }

            $distributor = User::query()->with('role')->lockForUpdate()->find($authorization->distributorId);
            $link = DistributorAccessLink::query()->where('user_id', $authorization->distributorId)->lockForUpdate()->first();
            if (! $authorization->isFinal
                || $distributor === null
                || $distributor->role->code !== RoleCode::DISTRIBUTOR
                || $link === null
                || $link->external_request_id !== $authorization->authorizationId
                || $link->authorized_by !== $authorization->authorizedByUserId
                || $link->branch_id !== $authorization->branchId
                || ! $authorization->authorizedAmount->isPositive()) {
                throw new CreditRuleViolation('La autorización final de la línea no es válida.', 'AUTH_SCOPE_DENIED', 403);
            }
            // The user lock serializes two first-time authorizations for a distributor.
            $concurrent = $this->lines->lockForDistributor($authorization->distributorId);
            if ($concurrent !== null) {
                $sameAuthorization = $concurrent->movements()
                    ->where('type', CreditMovementType::INITIAL_AUTHORIZATION->value)
                    ->where('source_id', $authorization->authorizationId)
                    ->exists();
                if ($sameAuthorization) {
                    $this->recorder->audit(
                        'CREDIT_DUPLICATE_ATTEMPT',
                        'IDEMPOTENT_REPLAY',
                        User::query()->find($authorization->authorizedByUserId),
                        $authorization->distributorId,
                        $authorization->branchId,
                        'credit_lines',
                        $concurrent->public_id,
                        reason: $authorization->reason,
                        metadata: ['operation' => CreditMovementType::INITIAL_AUTHORIZATION->value],
                        idempotencyKey: $authorization->idempotencyKey,
                    );

                    return $concurrent;
                }

                throw new CreditRuleViolation('La distribuidora ya tiene una línea de crédito.', 'CREDIT_LINE_ALREADY_EXISTS');
            }

            $domain = new CreditLine($authorization->authorizedAmount, Money::zero(), Money::zero());
            $line = CreditLineModel::query()->create([
                'distributor_id' => $authorization->distributorId,
                'total_authorized' => $domain->totalAuthorized->databaseValue(),
                'used_balance' => '0.0000',
                'available_balance' => $domain->availableBalance()->databaseValue(),
                'recovered_capital_total' => '0.0000',
                'lock_version' => 0,
            ]);
            $empty = new CreditLine(Money::zero(), Money::zero(), Money::zero());
            $movement = $this->movements->append(
                $line,
                $empty,
                $domain,
                CreditMovementType::INITIAL_AUTHORIZATION,
                'DISTRIBUTOR_FINAL_AUTHORIZATION',
                $authorization->authorizationId,
                User::query()->find($authorization->authorizedByUserId),
                $authorization->authorizedByUserId,
                $authorization->branchId,
                $authorization->reason,
                $authorization->idempotencyKey,
                [
                    'percentage' => (string) config('credit.percentage'),
                    'tolerance' => (string) config('credit.fifty_percent_tolerance'),
                ],
            );
            $restriction = $this->restrictions->create(
                $line->refresh(),
                RestrictionTriggerType::INITIAL_AUTHORIZATION,
                $authorization->authorizationId,
            );
            $actor = User::query()->find($authorization->authorizedByUserId);
            $after = $this->snapshot($line->refresh());
            $this->recorder->audit(
                'CREDIT_LINE_INITIAL_AUTHORIZED',
                'SUCCESS',
                $actor,
                $authorization->distributorId,
                $authorization->branchId,
                'credit_lines',
                (string) $line->public_id,
                null,
                $after,
                $authorization->reason,
                [
                    'movement_id' => $movement->public_id,
                    'restriction_id' => $restriction->public_id,
                    'tolerance' => $restriction->tolerance_amount,
                ],
                $authorization->idempotencyKey,
                authorizerId: $authorization->authorizedByUserId,
            );
            $payload = [
                'distributor_id' => $distributor->public_id,
                'branch_id' => $authorization->branchId,
                'actor_id' => $actor?->public_id,
                'amount' => $authorization->authorizedAmount->format(),
                'movement_id' => $movement->public_id,
                'restriction_id' => $restriction->public_id,
                'balances' => $after,
                'occurred_at' => now('UTC')->toIso8601String(),
            ];
            $this->recorder->event('CreditLineInitiallyAuthorized', "credit-initial:{$authorization->idempotencyKey}", $payload);
            $this->recorder->event(
                'InitialFiftyPercentRestrictionActivated',
                "credit-initial-restriction:{$authorization->idempotencyKey}",
                $payload,
            );

            return $line->refresh();
        }, 3);
    }

    /** @return array<string, string|int> */
    private function snapshot(CreditLineModel $line): array
    {
        return [
            'total_authorized' => (new Money($line->total_authorized))->format(),
            'used_balance' => (new Money($line->used_balance))->format(),
            'available_balance' => (new Money($line->available_balance))->format(),
            'recovered_capital_total' => (new Money($line->recovered_capital_total))->format(),
            'lock_version' => (int) $line->lock_version,
        ];
    }
}
