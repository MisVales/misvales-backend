<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\DTOs;

use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use App\Modules\Payment\Domain\ValueObjects\Money;

/** Snapshot financiero autoritativo recibido desde M10. */
final readonly class PendingComponents
{
    public function __construct(
        public Money $lateFee,
        public Money $interest,
        public Money $insurance,
        public Money $loanCommission,
        public Money $capital,
    ) {
        foreach ([$lateFee, $interest, $insurance, $loanCommission, $capital] as $component) {
            if ($component->isNegative()) {
                throw PaymentDomainException::financialInconsistent();
            }
        }
    }

    public function total(): Money
    {
        return $this->lateFee
            ->add($this->interest)
            ->add($this->insurance)
            ->add($this->loanCommission)
            ->add($this->capital);
    }
}
