<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\ValueObjects;

use App\Modules\Points\Domain\Exceptions\PointsDomainException;

/** Snapshot inmutable conservado por M10 para evaluar una relación histórica. */
final readonly class PointRuleSnapshot
{
    public function __construct(
        public string $divisorVersionId,
        public string $divisor,
        public string $multiplierVersionId,
        public int $multiplier,
        public string $penaltyRateVersionId,
        public string $penaltyRate,
    ) {
        if (! self::isDecimal($divisor) || self::compare($divisor, '0') <= 0 || $multiplier <= 0) {
            throw new PointsDomainException(
                'POINT_CONFIGURATION_INVALID',
                'El snapshot de puntos de la relación no es válido.',
            );
        }

        if (! self::isDecimal($penaltyRate)
            || self::compare($penaltyRate, '0') < 0
            || self::compare($penaltyRate, '1') > 0) {
            throw new PointsDomainException(
                'POINT_CONFIGURATION_INVALID',
                'El porcentaje congelado de penalización no es válido.',
            );
        }

        foreach ([$divisorVersionId, $multiplierVersionId, $penaltyRateVersionId] as $versionId) {
            if ($versionId === '') {
                throw new PointsDomainException(
                    'POINT_CONFIGURATION_NOT_FOUND',
                    'La relación no conserva todas las versiones de puntos requeridas.',
                );
            }
        }
    }

    private static function compare(string $left, string $right): int
    {
        return function_exists('bccomp')
            ? bccomp($left, $right, 4)
            : ((float) $left <=> (float) $right);
    }

    private static function isDecimal(string $value): bool
    {
        return preg_match('/^-?\d+(?:\.\d{1,6})?$/D', $value) === 1;
    }
}
