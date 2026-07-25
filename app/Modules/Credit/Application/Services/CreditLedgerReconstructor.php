<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;

final class CreditLedgerReconstructor
{
    /** @return array<string, Money> */
    public function reconstruct(CreditLineModel $line): array
    {
        $total = Money::zero();
        $used = Money::zero();
        $recovered = Money::zero();
        foreach ($line->movements()->oldest('id')->cursor() as $movement) {
            $total = $total->add(new Money($movement->total_delta));
            $used = $used->add(new Money($movement->used_delta));
            if ($movement->type->value === 'CAPITAL_RECOVERED') {
                $recovered = $recovered->add(new Money(ltrim((string) $movement->used_delta, '-')));
            }
            if (! $total->equals(new Money($movement->total_after))
                || ! $used->equals(new Money($movement->used_after))
                || ! $total->subtract($used)->equals(new Money($movement->available_after))) {
                throw new CreditRuleViolation('El libro contiene una secuencia de saldos inconsistente.', 'CREDIT_INVALID_BALANCE');
            }
        }

        return [
            'total_authorized' => $total,
            'used_balance' => $used,
            'available_balance' => $total->subtract($used),
            'recovered_capital_total' => $recovered,
        ];
    }

    public function assertMatches(CreditLineModel $line): void
    {
        $rebuilt = $this->reconstruct($line);
        if (! $rebuilt['total_authorized']->equals(new Money($line->total_authorized))
            || ! $rebuilt['used_balance']->equals(new Money($line->used_balance))
            || ! $rebuilt['available_balance']->equals(new Money($line->available_balance))
            || ! $rebuilt['recovered_capital_total']->equals(new Money($line->recovered_capital_total))) {
            throw new CreditRuleViolation('El saldo materializado no coincide con el libro.', 'CREDIT_INVALID_BALANCE');
        }
    }
}
