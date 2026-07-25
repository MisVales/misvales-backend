<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Perfil base y número visible creados de forma idempotente por M05. */
final readonly class DistributorProvisionResult
{
    public function __construct(
        public string $distributorId,
        public string $distributorNumber,
    ) {}
}
