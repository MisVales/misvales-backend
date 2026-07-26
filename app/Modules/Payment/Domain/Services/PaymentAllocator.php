<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Services;

use App\Modules\Payment\Domain\DTOs\PaymentAllocation;
use App\Modules\Payment\Domain\DTOs\PendingComponents;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use App\Modules\Payment\Domain\ValueObjects\Money;

/** Aplica recargo, interés, seguro, comisión y capital, en ese orden. */
final class PaymentAllocator
{
    public function allocate(Money $received, PendingComponents $pending): PaymentAllocation
    {
        if (! $received->isPositive()) {
            throw PaymentDomainException::invalidAllocation();
        }

        $balanceBefore = $pending->total();
        $applied = $received->min($balanceBefore);
        $remaining = $applied;

        $lateFee = $remaining->min($pending->lateFee);
        $remaining = $remaining->subtract($lateFee);
        $interest = $remaining->min($pending->interest);
        $remaining = $remaining->subtract($interest);
        $insurance = $remaining->min($pending->insurance);
        $remaining = $remaining->subtract($insurance);
        $loanCommission = $remaining->min($pending->loanCommission);
        $remaining = $remaining->subtract($loanCommission);
        $capital = $remaining->min($pending->capital);

        $allocated = $lateFee->add($interest)->add($insurance)->add($loanCommission)->add($capital);
        if (! $allocated->equals($applied)) {
            throw PaymentDomainException::invalidAllocation();
        }

        return new PaymentAllocation(
            received: $received,
            applied: $applied,
            excess: $received->subtract($applied),
            lateFee: $lateFee,
            interest: $interest,
            insurance: $insurance,
            loanCommission: $loanCommission,
            capital: $capital,
            balanceBefore: $balanceBefore,
            balanceAfter: $balanceBefore->subtract($applied),
        );
    }
}
