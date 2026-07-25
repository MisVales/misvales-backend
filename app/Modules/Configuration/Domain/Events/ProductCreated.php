<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho confirmado: se creó un producto con su primer borrador.
 */
final readonly class ProductCreated
{
    use Dispatchable;

    public function __construct(
        public string $productId,
        public string $versionId,
        public int $versionNumber,
        public string $createdBy,
        public string $occurredAt,
    ) {}
}
