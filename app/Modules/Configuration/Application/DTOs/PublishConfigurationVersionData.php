<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * DTO para publicar una versión de configuración.
 */
final readonly class PublishConfigurationVersionData
{
    public function __construct(
        public string $versionPublicId,
        public CarbonImmutable $effectiveFrom,
        public string $reason,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
