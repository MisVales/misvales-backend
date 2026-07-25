<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Sucursal y coordinador resueltos por el módulo propietario de organización. */
final readonly class ResponsibleContext
{
    public function __construct(
        public int $branchId,
        public int $coordinatorUserId,
    ) {}
}
