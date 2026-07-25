<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para desactivar un producto.
 */
final readonly class DeactivateProductData
{
    public function __construct(
        public string $productPublicId,
        public string $reason,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
