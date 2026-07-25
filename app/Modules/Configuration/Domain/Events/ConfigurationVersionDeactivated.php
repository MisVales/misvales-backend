<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho confirmado: se desactivó una versión de configuración.
 */
final readonly class ConfigurationVersionDeactivated
{
    use Dispatchable;

    public function __construct(
        public string $definitionId,
        public string $versionId,
        public string $key,
        public int $versionNumber,
        public string $deactivatedBy,
        public string $reason,
        public string $occurredAt,
    ) {}
}
