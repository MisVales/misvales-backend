<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * DTO de resultado de resolución de una categoría.
 */
final readonly class ResolvedCategory
{
    public function __construct(
        public string $categoryPublicId,
        public string $versionPublicId,
        public int $versionNumber,
        public string $name,
        public string $description,
        public string $distributorProfitRate,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo,
    ) {}
}
