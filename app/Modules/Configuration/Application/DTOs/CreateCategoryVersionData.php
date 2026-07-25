<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para crear un borrador de nueva versión de categoría.
 */
final readonly class CreateCategoryVersionData
{
    public function __construct(
        public string $categoryPublicId,
        public string $name,
        public string $description,
        public string $distributorProfitRate,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
