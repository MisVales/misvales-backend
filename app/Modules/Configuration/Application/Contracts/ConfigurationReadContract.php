<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Contracts;

use App\Modules\Configuration\Application\DTOs\ResolvedConfiguration;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use Carbon\CarbonImmutable;

/**
 * Contrato de lectura de configuraciones para módulos consumidores (C04).
 *
 * Los módulos consumidores no consultan tablas de M03 directamente.
 * Utilizan este contrato para obtener valores tipados y validados.
 */
interface ConfigurationReadContract
{
    /**
     * Resuelve una configuración por clave y fecha.
     *
     * @throws \App\Modules\Configuration\Domain\Exceptions\ConfigurationException
     */
    public function resolve(ConfigurationKey $key, CarbonImmutable $at): ResolvedConfiguration;

    /**
     * Resuelve varias configuraciones en una sola operación consistente.
     *
     * @param ConfigurationKey[] $keys
     *
     * @return array<string, ResolvedConfiguration>
     *
     * @throws \App\Modules\Configuration\Domain\Exceptions\ConfigurationException
     */
    public function resolveMany(array $keys, CarbonImmutable $at): array;
}
