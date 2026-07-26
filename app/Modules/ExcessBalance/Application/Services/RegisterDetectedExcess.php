<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Modules\ExcessBalance\Application\Contracts\DetectedExcessRegistrar;
use App\Modules\ExcessBalance\Application\DTOs\DetectedExcess;
use App\Modules\ExcessBalance\Domain\Enums\ExcessBalanceBucket;
use App\Modules\ExcessBalance\Domain\Enums\ExcessLedgerEntryType;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\ExcessBalance\Domain\Services\ExcessAmountCalculator;
use App\Modules\ExcessBalance\Domain\Services\ExcessBalanceInvariant;
use App\Modules\ExcessBalance\Domain\ValueObjects\Money;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RegisterDetectedExcess implements DetectedExcessRegistrar
{
    public function __construct(
        private ExcessAmountCalculator $calculator,
        private ExcessBalanceInvariant $invariant,
        private ExcessRecorder $recorder,
    ) {}

    public function register(DetectedExcess $detected): array
    {
        $paid = new Money($detected->paidAmount);
        $applied = new Money($detected->appliedAmount);
        $excess = new Money($detected->excessAmount);
        $previousBalance = new Money($detected->previousBalance);
        $this->calculator->assertProvided($paid, $applied, $excess);
        if (! $applied->equals($previousBalance)) {
            throw ExcessBalanceException::amountInvalid();
        }
        $this->invariant->assert(
            $excess,
            $excess,
            Money::zero(),
            Money::zero(),
            Money::zero(),
            Money::zero(),
        );

        return DB::transaction(function () use ($detected, $excess): array {
            $existing = ExcessBalanceModel::query()
                ->where('payment_allocation_id', $detected->paymentAllocationId)
                ->orWhere('bank_movement_id', $detected->bankMovementId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (
                    $existing->payment_allocation_id === $detected->paymentAllocationId
                    && $existing->bank_movement_id === $detected->bankMovementId
                    && $existing->origin_relation_id === $detected->relationId
                    && (int) $existing->distributor_id === $detected->distributorId
                    && (string) $existing->original_amount === $excess->value()
                ) {
                    return $this->response($existing);
                }

                throw ExcessBalanceException::alreadyRegistered();
            }

            $balance = ExcessBalanceModel::query()->create([
                'public_number' => $this->folio('EXC'),
                'distributor_id' => $detected->distributorId,
                'branch_id' => $detected->branchId,
                'origin_relation_id' => $detected->relationId,
                'bank_movement_id' => $detected->bankMovementId,
                'payment_allocation_id' => $detected->paymentAllocationId,
                'original_amount' => $excess->value(),
                'retained_amount' => $excess->value(),
                'available_amount' => '0.0000',
                'applied_amount' => '0.0000',
                'reserved_refund_amount' => '0.0000',
                'refunded_amount' => '0.0000',
                'currency' => 'MXN',
                'status' => ExcessBalanceStatus::PENDING_DECISION,
                'effective_paid_at' => $detected->effectivePaidAt,
                'lock_version' => 1,
            ]);
            $amounts = $this->recorder->amounts($balance);
            $this->recorder->ledger(
                $balance->id,
                ExcessLedgerEntryType::EXCESS_DETECTED,
                $excess->value(),
                null,
                ExcessBalanceBucket::RETAINED,
                'detected:'.$detected->idempotencyKey,
                null,
                metadata: [
                    'bank_movement_id' => $detected->bankMovementId,
                    'payment_allocation_id' => $detected->paymentAllocationId,
                ],
            );
            $this->recorder->history(
                $balance,
                null,
                ExcessBalanceStatus::PENDING_DECISION->value,
                [
                    'original' => '0.0000',
                    'retained' => '0.0000',
                    'available' => '0.0000',
                    'reserved' => '0.0000',
                    'applied' => '0.0000',
                    'refunded' => '0.0000',
                ],
                $amounts,
                'detected:'.$detected->idempotencyKey,
                null,
            );
            $this->recorder->audit(
                'EXCESS_DETECTED',
                'SUCCESS',
                'excess_balances',
                $balance->id,
                null,
                after: $amounts,
                metadata: [
                    'payment_allocation_id' => $detected->paymentAllocationId,
                    'correlation_id' => $detected->correlationId,
                ],
                systemBranchId: $detected->branchId,
            );
            $this->recorder->event('ExcessDetected', $balance, [
                'amount' => $excess->value(),
                'correlation_id' => $detected->correlationId,
            ], 'detected:'.$detected->idempotencyKey);
            $this->recorder->event('ExcessDecisionPending', $balance, [
                'amount' => $excess->value(),
                'correlation_id' => $detected->correlationId,
            ], 'decision-pending:'.$detected->idempotencyKey);

            return $this->response($balance);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function response(ExcessBalanceModel $balance): array
    {
        return [
            'id' => $balance->id,
            'public_number' => $balance->public_number,
            'status' => $balance->status->value,
            'original_amount' => (string) $balance->original_amount,
            'retained_amount' => (string) $balance->retained_amount,
            'lock_version' => (int) $balance->lock_version,
        ];
    }

    private function folio(string $prefix): string
    {
        return $prefix.'-'.Str::upper(Str::random(16));
    }
}
