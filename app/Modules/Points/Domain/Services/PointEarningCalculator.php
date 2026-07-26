<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Services;

use App\Modules\Points\Domain\Exceptions\PointsDomainException;

/** Calcula piso(base/divisor) antes de aplicar el multiplicador. */
final class PointEarningCalculator
{
    public function calculate(string $productsCapitalBasis, string $divisor, int $multiplier): int
    {
        if (! $this->isDecimal($productsCapitalBasis) || $this->compare($productsCapitalBasis, '0') < 0) {
            throw new PointsDomainException(
                'RELATION_POINT_BASIS_INVALID',
                'La base monetaria de productos no puede ser negativa.',
            );
        }

        if (! $this->isDecimal($divisor) || $this->compare($divisor, '0') <= 0 || $multiplier <= 0) {
            throw new PointsDomainException(
                'POINT_CONFIGURATION_INVALID',
                'El divisor y multiplicador deben ser positivos.',
            );
        }

        $units = function_exists('bcdiv')
            ? (int) bcdiv($productsCapitalBasis, $divisor, 0)
            : (int) floor((float) $productsCapitalBasis / (float) $divisor);

        return $units * $multiplier;
    }

    private function compare(string $left, string $right): int
    {
        return function_exists('bccomp')
            ? bccomp($left, $right, 4)
            : ((float) $left <=> (float) $right);
    }

    private function isDecimal(string $value): bool
    {
        return preg_match('/^-?\d+(?:\.\d{1,4})?$/D', $value) === 1;
    }
}
