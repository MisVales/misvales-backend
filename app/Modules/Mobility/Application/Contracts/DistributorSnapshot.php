<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Application\Contracts;

/** Datos organizacionales mínimos, sin copiar el perfil propietario de M05. */
final readonly class DistributorSnapshot
{
    public function __construct(
        public string $id,
        public int $internalId,
        public int $branchId,
        public ?int $coordinatorId,
        public bool $active,
    ) {}
}
