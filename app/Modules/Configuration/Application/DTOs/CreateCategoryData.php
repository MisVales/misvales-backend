<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para crear una categoría con su primer borrador.
 */
final readonly class CreateCategoryData
{
    public function __construct(
        public string $name,
        public string $description,
        public string $distributorProfitRate,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
