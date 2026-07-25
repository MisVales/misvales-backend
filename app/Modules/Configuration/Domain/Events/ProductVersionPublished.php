<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho confirmado: se publicó una versión de producto.
 */
final readonly class ProductVersionPublished
{
    use Dispatchable;

    public function __construct(
        public string $productId,
        public string $versionId,
        public int $versionNumber,
        public string $effectiveFrom,
        public string $publishedBy,
        public string $occurredAt,
    ) {}
}
