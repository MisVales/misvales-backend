<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\DTOs;

/** Resultado confirmado por el motor financiero de M11. */
final readonly class CreditBalancePaymentResult
{
    public function __construct(
        public string $paymentAllocationId,
        public string $appliedAmount,
        public string $lateFeeAmount,
        public string $interestAmount,
        public string $insuranceAmount,
        public string $loanCommissionAmount,
        public string $capitalAmount,
        public string $balanceAfter,
    ) {}
}
