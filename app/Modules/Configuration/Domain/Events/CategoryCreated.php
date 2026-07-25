<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho confirmado: se creó una categoría con su primer borrador.
 */
final readonly class CategoryCreated
{
    use Dispatchable;

    public function __construct(
        public string $categoryId,
        public string $versionId,
        public int $versionNumber,
        public string $createdBy,
        public string $occurredAt,
    ) {}
}
