<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Aggregates;

use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\ValueObjects\Money;

final readonly class CreditLine
{
    public function __construct(
        public Money $totalAuthorized,
        public Money $usedBalance,
        public Money $recoveredCapitalTotal,
    ) {
        if ($totalAuthorized->isNegative()
            || $usedBalance->isNegative()
            || $usedBalance->greaterThan($totalAuthorized)
            || $recoveredCapitalTotal->isNegative()) {
            throw new CreditRuleViolation('Los saldos de la línea no son válidos.', 'CREDIT_INVALID_BALANCE');
        }
    }

    public function availableBalance(): Money
    {
        return $this->totalAuthorized->subtract($this->usedBalance);
    }

    public function increase(Money $amount): self
    {
        $this->assertPositive($amount);

        return new self($this->totalAuthorized->add($amount), $this->usedBalance, $this->recoveredCapitalTotal);
    }

    public function useCapital(Money $amount): self
    {
        $this->assertPositive($amount);
        if ($amount->greaterThan($this->availableBalance())) {
            throw new CreditRuleViolation('El capital supera el saldo disponible.', 'CREDIT_INSUFFICIENT', 422);
        }

        return new self($this->totalAuthorized, $this->usedBalance->add($amount), $this->recoveredCapitalTotal);
    }

    /** @return array{self, Money} */
    public function recoverCapital(Money $requested): array
    {
        $this->assertPositive($requested);
        if (! $this->usedBalance->isPositive()) {
            throw new CreditRuleViolation('La línea no tiene capital pendiente por recuperar.', 'CAPITAL_RECOVERY_EXCEEDS_USED_BALANCE');
        }

        $applied = $requested->min($this->usedBalance);

        return [
            new self(
                $this->totalAuthorized,
                $this->usedBalance->subtract($applied),
                $this->recoveredCapitalTotal->add($applied),
            ),
            $applied,
        ];
    }

    private function assertPositive(Money $amount): void
    {
        if (! $amount->isPositive()) {
            throw new CreditRuleViolation('El importe debe ser mayor que cero.', 'CREDIT_INCREASE_AMOUNT_INVALID', 422);
        }
    }
}
