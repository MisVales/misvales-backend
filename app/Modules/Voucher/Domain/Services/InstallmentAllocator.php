<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Services;

use App\Modules\Voucher\Domain\ValueObjects\Money;

final class InstallmentAllocator
{
    /** @return list<Money> */
    public function allocate(Money $total, int $payments): array
    {
        $regular = $total->divide($payments);
        $allocated = Money::zero();
        $result = [];

        for ($number = 1; $number <= $payments; $number++) {
            $amount = $number === $payments ? $total->subtract($allocated) : $regular;
            $result[] = $amount;
            $allocated = $allocated->add($amount);
        }

        return $result;
    }
}
