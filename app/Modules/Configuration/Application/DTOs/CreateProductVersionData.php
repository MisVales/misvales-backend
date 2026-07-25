<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para crear un borrador de nueva versión de producto.
 */
final readonly class CreateProductVersionData
{
    public function __construct(
        public string $productPublicId,
        public string $amount,
        public string $loanCommissionRate,
        public string $interestRatePerFortnight,
        public string $insuranceAmount,
        public int $fortnightCount,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
