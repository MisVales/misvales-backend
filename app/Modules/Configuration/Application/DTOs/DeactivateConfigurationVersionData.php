<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para desactivar una versión de configuración.
 */
final readonly class DeactivateConfigurationVersionData
{
    public function __construct(
        public string $versionPublicId,
        public string $reason,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
