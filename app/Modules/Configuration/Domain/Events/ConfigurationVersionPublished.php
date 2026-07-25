<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho confirmado: se publicó una versión de configuración.
 */
final readonly class ConfigurationVersionPublished
{
    use Dispatchable;

    public function __construct(
        public string $definitionId,
        public string $versionId,
        public string $key,
        public int $versionNumber,
        public string $effectiveFrom,
        public string $publishedBy,
        public string $occurredAt,
    ) {}
}
