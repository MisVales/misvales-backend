<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * DTO de resultado de resolución de una configuración (C04).
 *
 * Contiene el valor tipado, la versión y la vigencia.
 */
final readonly class ResolvedConfiguration
{
    public function __construct(
        public string $definitionPublicId,
        public string $key,
        public string $type,
        public string $versionPublicId,
        public int $versionNumber,
        public string $value,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveTo,
    ) {}
}
