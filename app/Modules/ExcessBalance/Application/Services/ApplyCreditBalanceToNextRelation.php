<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Modules\ExcessBalance\Application\Contracts\CreditBalanceApplicationPort;
use App\Modules\ExcessBalance\Domain\Enums\ExcessBalanceBucket;
use App\Modules\ExcessBalance\Domain\Enums\ExcessLedgerEntryType;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\ExcessBalance\Domain\Services\ExcessBalanceInvariant;
use App\Modules\ExcessBalance\Domain\Services\ExcessStateMachine;
use App\Modules\ExcessBalance\Domain\ValueObjects\Money;
use App\Modules\ExcessBalance\Infrastructure\Persistence\Eloquent\Models\ExcessApplicationModel;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ApplyCreditBalanceToNextRelation
{
    public function __construct(
        private CreditBalanceApplicationPort $payments,
        private ExcessBalanceInvariant $invariant,
        private ExcessStateMachine $states,
        private ExcessRecorder $recorder,
    ) {}

    /** @return array<string, mixed> */
    public function execute(
        string $eventId,
        string $relationId,
        int $distributorId,
        int $branchId,
    ): array {
        return DB::transaction(function () use ($eventId, $relationId, $distributorId, $branchId): array {
            $processed = DB::table('excess_processed_events')->where('event_id', $eventId)->first();
            if ($processed !== null) {
                $payload = $processed->response_payload;
                if (is_string($payload)) {
                    $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                    return is_array($decoded) ? $decoded : [];
                }

                return (array) $payload;
            }

            $candidateCount = ExcessBalanceModel::query()
                ->where('distributor_id', $distributorId)
                ->whereIn('status', [
                    ExcessBalanceStatus::CREDIT_BALANCE,
                    ExcessBalanceStatus::PARTIALLY_APPLIED,
                ])
                ->where('available_amount', '>', 0)
                ->count();
            if ($candidateCount === 0) {
                return $this->completeEvent($eventId, $relationId, ['result' => 'NO_AVAILABLE_BALANCE']);
            }
            if ($candidateCount > 1) {
                throw ExcessBalanceException::pendingDefinition(
                    'No está definido el orden de consumo entre varios saldos a favor.',
                );
            }

            // Contrato global: M10 bloquea relación y M11 bloquea línea antes del excedente.
            $relation = $this->payments->lockRelationAndCredit($relationId, $distributorId, $branchId);
            if (
                $relation->relationId !== $relationId
                || $relation->distributorId !== $distributorId
                || $relation->branchId !== $branchId
                || ! $relation->acceptsPayments
            ) {
                throw ExcessBalanceException::relationNotEligible();
            }
            if (! $relation->isSubsequent) {
                throw ExcessBalanceException::relationNotSubsequent();
            }

            $balance = ExcessBalanceModel::query()
                ->where('distributor_id', $distributorId)
                ->whereIn('status', [
                    ExcessBalanceStatus::CREDIT_BALANCE,
                    ExcessBalanceStatus::PARTIALLY_APPLIED,
                ])
                ->where('available_amount', '>', 0)
                ->lockForUpdate()
                ->first()
                ?? throw ExcessBalanceException::insufficientAvailable();
            if ($balance->origin_relation_id === $relationId) {
                throw ExcessBalanceException::relationNotSubsequent();
            }

            $available = new Money((string) $balance->available_amount);
            $pending = new Money($relation->pendingAmount);
            $amount = $available->min($pending);
            if (! $amount->isPositive()) {
                return $this->completeEvent($eventId, $relationId, ['result' => 'NO_APPLICABLE_AMOUNT']);
            }
            $this->assertInvariant($balance);
            $paymentAllocationId = (string) Str::uuid();
            $application = ExcessApplicationModel::query()->create([
                'excess_balance_id' => $balance->id,
                'relation_id' => $relationId,
                'amount' => $amount->value(),
                'available_before' => $available->value(),
                'available_after' => $available->subtract($amount)->value(),
                'payment_allocation_id' => $paymentAllocationId,
                'effective_at' => null,
                'applied_at' => now('UTC'),
                'status' => 'APPLIED',
                'idempotency_key' => 'relation-available:'.$eventId,
                'created_by' => null,
            ]);
            $payment = $this->payments->apply(
                $relation,
                $application->id,
                $paymentAllocationId,
                $amount->value(),
                'credit-balance:'.$eventId,
            );
            $capital = new Money($payment->capitalAmount);
            if ($payment->paymentAllocationId !== $paymentAllocationId
                || ! (new Money($payment->appliedAmount))->equals($amount)
                || ! $capital->lessThanOrEqual($amount)) {
                throw ExcessBalanceException::invariantViolated();
            }
            $before = $this->recorder->amounts($balance);
            $remaining = $available->subtract($amount);
            $newStatus = $remaining->isPositive()
                ? ExcessBalanceStatus::PARTIALLY_APPLIED
                : ExcessBalanceStatus::FULLY_APPLIED;
            $previousStatus = $balance->status->value;
            $this->states->assertTransition($balance->status, $newStatus);
            $balance->forceFill([
                'available_amount' => $remaining->value(),
                'applied_amount' => (new Money((string) $balance->applied_amount))->add($amount)->value(),
                'status' => $newStatus,
                'lock_version' => $balance->lock_version + 1,
            ])->save();
            $this->assertInvariant($balance);
            $after = $this->recorder->amounts($balance);
            $operationKey = 'credit-applied:'.$eventId;
            $this->recorder->ledger(
                $balance->id,
                ExcessLedgerEntryType::CREDIT_APPLIED,
                $amount->value(),
                ExcessBalanceBucket::AVAILABLE,
                ExcessBalanceBucket::APPLIED,
                $operationKey,
                null,
                applicationId: $application->id,
                metadata: [
                    'payment_allocation_id' => $payment->paymentAllocationId,
                    'capital_amount' => $capital->value(),
                    'temporal_classification' => 'PENDING_DEFINITION',
                ],
            );
            $this->recorder->history(
                $balance,
                $previousStatus,
                $balance->status->value,
                $before,
                $after,
                $operationKey,
                null,
                applicationId: $application->id,
            );
            $this->recorder->audit(
                'CREDIT_BALANCE_APPLIED',
                'SUCCESS',
                'excess_applications',
                $application->id,
                null,
                $before,
                $after,
                [
                    'relation_id' => $relationId,
                    'payment_allocation_id' => $payment->paymentAllocationId,
                    'late_fee_amount' => $payment->lateFeeAmount,
                    'interest_amount' => $payment->interestAmount,
                    'insurance_amount' => $payment->insuranceAmount,
                    'loan_commission_amount' => $payment->loanCommissionAmount,
                    'capital_amount' => $payment->capitalAmount,
                    'classification' => null,
                ],
                systemBranchId: $branchId,
            );
            $eventType = $remaining->isPositive()
                ? 'CreditBalancePartiallyApplied'
                : 'CreditBalanceFullyApplied';
            $this->recorder->event('CreditBalanceApplied', $balance, [
                'application_id' => $application->id,
                'receiver_relation_id' => $relationId,
                'amount' => $amount->value(),
                'capital_amount' => $capital->value(),
                'classification' => null,
                'correlation_id' => $eventId,
            ], $operationKey);
            $this->recorder->event($eventType, $balance, [
                'application_id' => $application->id,
                'receiver_relation_id' => $relationId,
                'amount' => $amount->value(),
                'remaining_amount' => $remaining->value(),
                'correlation_id' => $eventId,
            ], $eventType.':'.$eventId);

            return $this->completeEvent($eventId, $relationId, [
                'result' => 'APPLIED',
                'application_id' => $application->id,
                'excess_balance_id' => $balance->id,
                'payment_allocation_id' => $payment->paymentAllocationId,
                'applied_amount' => $amount->value(),
                'capital_amount' => $capital->value(),
                'available_after' => $remaining->value(),
                'relation_balance_after' => $payment->balanceAfter,
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function completeEvent(string $eventId, string $relationId, array $response): array
    {
        DB::table('excess_processed_events')->insert([
            'event_id' => $eventId,
            'event_type' => 'RelationAvailableForPayment',
            'resource_id' => $relationId,
            'result' => (string) $response['result'],
            'processed_at' => now('UTC'),
            'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
        ]);

        return $response;
    }

    private function assertInvariant(ExcessBalanceModel $balance): void
    {
        $this->invariant->assert(
            new Money((string) $balance->original_amount),
            new Money((string) $balance->retained_amount),
            new Money((string) $balance->available_amount),
            new Money((string) $balance->reserved_refund_amount),
            new Money((string) $balance->applied_amount),
            new Money((string) $balance->refunded_amount),
        );
    }
}
