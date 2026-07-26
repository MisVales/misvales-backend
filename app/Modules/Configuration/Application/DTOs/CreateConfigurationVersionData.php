<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para crear una versión borrador de configuración.
 */
final readonly class CreateConfigurationVersionData
{
    public function __construct(
        public string $key,
        public string $value,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
