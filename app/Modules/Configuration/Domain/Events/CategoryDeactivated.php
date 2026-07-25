<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho confirmado: se desactivó una categoría.
 */
final readonly class CategoryDeactivated
{
    use Dispatchable;

    public function __construct(
        public string $categoryId,
        public string $deactivatedBy,
        public string $reason,
        public string $occurredAt,
    ) {}
}
