<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Domain\Services;

use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\ExcessBalance\Domain\ValueObjects\Money;

final class ExcessBalanceInvariant
{
    public function assert(
        Money $original,
        Money $retained,
        Money $available,
        Money $reserved,
        Money $applied,
        Money $refunded,
    ): void {
        foreach ([$original, $retained, $available, $reserved, $applied, $refunded] as $amount) {
            if ($amount->isNegative()) {
                throw ExcessBalanceException::invariantViolated();
            }
        }

        if (! $original->isPositive()
            || ! $retained->add($available)->add($reserved)->add($applied)->add($refunded)->equals($original)) {
            throw ExcessBalanceException::invariantViolated();
        }
    }
}
