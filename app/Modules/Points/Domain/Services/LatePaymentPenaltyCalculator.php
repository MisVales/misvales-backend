<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Services;

use App\Modules\Points\Domain\Exceptions\PointsDomainException;

final class LatePaymentPenaltyCalculator
{
    public function calculate(int $totalPoints, string $penaltyRate): int
    {
        if ($totalPoints < 0
            || ! $this->isDecimal($penaltyRate)
            || $this->compare($penaltyRate, '0') < 0
            || $this->compare($penaltyRate, '1') > 0) {
            throw new PointsDomainException(
                'POINT_CONFIGURATION_INVALID',
                'Los datos de la penalización no son válidos.',
            );
        }

        return function_exists('bcmul')
            ? (int) bcmul((string) $totalPoints, $penaltyRate, 0)
            : (int) floor($totalPoints * (float) $penaltyRate);
    }

    private function compare(string $left, string $right): int
    {
        return function_exists('bccomp')
            ? bccomp($left, $right, 4)
            : ((float) $left <=> (float) $right);
    }

    private function isDecimal(string $value): bool
    {
        return preg_match('/^-?\d+(?:\.\d{1,6})?$/D', $value) === 1;
    }
}
