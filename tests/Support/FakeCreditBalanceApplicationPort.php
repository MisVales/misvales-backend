<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\ExcessBalance\Application\Contracts\CreditBalanceApplicationPort;
use App\Modules\ExcessBalance\Application\DTOs\CreditBalancePaymentResult;
use App\Modules\ExcessBalance\Application\DTOs\LockedRelation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class FakeCreditBalanceApplicationPort implements CreditBalanceApplicationPort
{
    public function __construct(private string $pendingAmount) {}

    public function lockRelationAndCredit(
        string $relationId,
        int $distributorId,
        int $branchId,
    ): LockedRelation {
        return new LockedRelation(
            relationId: $relationId,
            distributorId: $distributorId,
            branchId: $branchId,
            pendingAmount: $this->pendingAmount,
            acceptsPayments: true,
            isSubsequent: true,
            availableAt: CarbonImmutable::parse('2026-07-26T00:00:00Z'),
        );
    }

    public function apply(
        LockedRelation $relation,
        string $excessApplicationId,
        string $paymentAllocationId,
        string $amount,
        string $idempotencyKey,
    ): CreditBalancePaymentResult {
        $capital = bcsub($amount, '5.0000', 4);
        DB::table('payment_allocations')->insert([
            'id' => $paymentAllocationId,
            'relation_id' => $relation->relationId,
            'bank_movement_id' => null,
            'excess_application_id' => $excessApplicationId,
            'source_type' => 'CREDIT_BALANCE',
            'received_amount' => $amount,
            'applied_amount' => $amount,
            'excess_amount' => '0.0000',
            'late_fee_amount' => '5.0000',
            'interest_amount' => '0.0000',
            'insurance_amount' => '0.0000',
            'loan_commission_amount' => '0.0000',
            'capital_amount' => $capital,
            'balance_before' => $this->pendingAmount,
            'balance_after' => bcsub($this->pendingAmount, $amount, 4),
            'effective_at' => now('UTC'),
            'applied_at' => now('UTC'),
            'application_mode' => 'CREDIT_BALANCE',
            'manual_reconciliation_id' => null,
            'idempotency_key' => $idempotencyKey,
            'created_by' => null,
            'created_at' => now('UTC'),
        ]);

        return new CreditBalancePaymentResult(
            paymentAllocationId: $paymentAllocationId,
            appliedAmount: $amount,
            lateFeeAmount: '5.0000',
            interestAmount: '0.0000',
            insuranceAmount: '0.0000',
            loanCommissionAmount: '0.0000',
            capitalAmount: $capital,
            balanceAfter: bcsub($this->pendingAmount, $amount, 4),
        );
    }
}
