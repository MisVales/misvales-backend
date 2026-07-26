<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Services;

use App\Modules\Points\Domain\Exceptions\PointsDomainException;

final class RedemptionAmountCalculator
{
    /** Devuelve un decimal con precisión interna de cuatro posiciones. */
    public function calculate(int $points, string $pointValue): string
    {
        if ($points <= 0 || ! $this->isDecimal($pointValue) || $this->compare($pointValue, '0') <= 0) {
            throw new PointsDomainException(
                'POINT_CONFIGURATION_INVALID',
                'Los puntos y el valor monetario deben ser positivos.',
            );
        }

        return function_exists('bcmul')
            ? bcmul((string) $points, $pointValue, 4)
            : number_format($points * (float) $pointValue, 4, '.', '');
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
