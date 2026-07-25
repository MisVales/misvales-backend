<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho confirmado: se desactivó un producto.
 */
final readonly class ProductDeactivated
{
    use Dispatchable;

    public function __construct(
        public string $productId,
        public string $deactivatedBy,
        public string $reason,
        public string $occurredAt,
    ) {}
}
