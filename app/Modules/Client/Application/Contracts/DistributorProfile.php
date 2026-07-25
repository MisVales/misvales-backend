<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Proyección mínima del perfil vigente administrado por M05. */
final readonly class DistributorProfile
{
    public function __construct(
        public string $distributorId,
        public string $number,
        public int $branchId,
        public string $branchPublicId,
        public string $branchName,
    ) {}
}
