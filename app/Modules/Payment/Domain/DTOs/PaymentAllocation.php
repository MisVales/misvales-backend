<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Modules\Payment\Domain\ValueObjects\Money;

/** Resultado exacto e inmutable del orden financiero de M11. */
final readonly class PaymentAllocation
{
    public function __construct(
        public Money $received,
        public Money $applied,
        public Money $excess,
        public Money $lateFee,
        public Money $interest,
        public Money $insurance,
        public Money $loanCommission,
        public Money $capital,
        public Money $balanceBefore,
        public Money $balanceAfter,
    ) {}

    public function recoveredCreditLine(): Money
    {
        return $this->capital;
    }
}
