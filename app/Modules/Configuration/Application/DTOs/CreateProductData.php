<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para crear un producto con su primer borrador.
 */
final readonly class CreateProductData
{
    public function __construct(
        public string $amount,
        public string $loanCommissionRate,
        public string $interestRatePerFortnight,
        public string $insuranceAmount,
        public int $fortnightCount,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
