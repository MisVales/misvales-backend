<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Domain\Services;

use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\ExcessBalance\Domain\ValueObjects\Money;

final class ExcessAmountCalculator
{
    public function calculate(Money $paid, Money $applied): Money
    {
        $excess = $paid->subtract($applied);
        if (! $excess->isPositive()) {
            throw ExcessBalanceException::amountInvalid();
        }

        return $excess;
    }

    public function assertProvided(Money $paid, Money $applied, Money $provided): void
    {
        if (! $this->calculate($paid, $applied)->equals($provided)) {
            throw ExcessBalanceException::amountInvalid();
        }
    }
}
