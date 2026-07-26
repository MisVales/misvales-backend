<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\ValueObjects;

use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;

/** Importe decimal exacto con cuatro decimales. */
final readonly class OverdueBalance
{
    public string $value;

    public function __construct(string $value)
    {
        if (! preg_match('/^\d{1,14}(?:\.\d{1,4})?$/', $value) || bccomp($value, '0', 4) < 0) {
            throw RiskDelinquencyException::sourceInconsistent();
        }

        $this->value = bcadd($value, '0', 4);
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', 4) === 0;
    }
}
