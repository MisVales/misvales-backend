<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Rules;

use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\ValueObjects\CreditRange;
use App\Modules\Credit\Domain\ValueObjects\Money;

final readonly class FiftyPercentRule
{
    public function range(
        Money $baseTotalAuthorized,
        Money $availableBalance,
        Money $tolerance,
        string $percentage = '0.5000',
    ): CreditRange {
        $reference = $baseTotalAuthorized->multiply($percentage);
        $lower = Money::zero()->max($reference->subtract($tolerance));
        $upper = $availableBalance->min($reference->add($tolerance));

        return new CreditRange($reference, $tolerance, $lower, $upper);
    }

    public function assertAdmissible(CreditRange $range, Money $capital): void
    {
        if (! $range->hasAdmissibleAmount()) {
            throw new CreditRuleViolation(
                'El saldo disponible no permite un importe dentro del rango del 50 %.',
                'CREDIT_50_PERCENT_NO_ADMISSIBLE_AMOUNT',
            );
        }

        if (! $range->admits($capital)) {
            throw new CreditRuleViolation(
                'El capital no se encuentra dentro del rango especial del 50 %.',
                'CREDIT_50_PERCENT_RULE_NOT_SATISFIED',
                422,
            );
        }
    }
}
