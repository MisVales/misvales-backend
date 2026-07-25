<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Identidades generadas por M07 para la apertura inicial. */
final readonly class CreditLineProvisionResult
{
    public function __construct(
        public string $creditLineId,
        public string $initialMovementId,
        public string $firstVoucherRestrictionId,
    ) {}
}
