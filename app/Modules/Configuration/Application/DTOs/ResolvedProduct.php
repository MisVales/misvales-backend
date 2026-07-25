<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * DTO de resultado de resolución de un producto vigente.
 */
final readonly class ResolvedProduct
{
    public function __construct(
        public string $productPublicId,
        public string $versionPublicId,
        public int $versionNumber,
        public string $amount,
        public string $loanCommissionRate,
        public string $interestRatePerFortnight,
        public string $insuranceAmount,
        public int $fortnightCount,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo,
    ) {}
}
