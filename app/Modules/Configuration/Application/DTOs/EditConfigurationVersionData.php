<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para editar un borrador de versión de configuración.
 */
final readonly class EditConfigurationVersionData
{
    public function __construct(
        public string $versionPublicId,
        public string $value,
        public int $lockVersion,
        public int $actorUserId,
    ) {}
}
