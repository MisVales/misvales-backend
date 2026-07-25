<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para desactivar una categoría.
 */
final readonly class DeactivateCategoryData
{
    public function __construct(
        public string $categoryPublicId,
        public string $reason,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
