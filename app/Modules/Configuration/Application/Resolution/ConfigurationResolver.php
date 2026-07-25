<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Resolution;

use App\Modules\Configuration\Application\DTOs\ResolvedConfiguration;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentConfigurationRepository;
use Carbon\CarbonImmutable;

/**
 * Servicio de resolución de configuración por clave y fecha (C04).
 *
 * La resolución es determinista: la misma clave y fecha devuelven
 * la misma versión mientras no se alteren datos históricos.
 *
 * @throws ConfigurationException Cuando no existe una versión aplicable.
 */
final class ConfigurationResolver
{
    public function __construct(
        private readonly EloquentConfigurationRepository $repository,
    ) {}

    /**
     * Resuelve la versión publicada vigente de una configuración.
     *
     * @param ConfigurationKey $key  Clave estable de la configuración.
     * @param CarbonImmutable  $at   Fecha y hora efectiva de resolución.
     *
     * @throws ConfigurationException Si la clave no existe o no hay versión aplicable.
     */
    public function resolve(ConfigurationKey $key, CarbonImmutable $at): ResolvedConfiguration
    {
        $definition = $this->repository->findDefinitionByKey($key);

        if ($definition === null) {
            throw ConfigurationException::notFound("La configuración «{$key->value}» no existe.");
        }

        $version = $this->repository->resolveAt($definition, $at);

        if ($version === null) {
            throw ConfigurationException::valueMissing($key->value, $at->toIso8601String());
        }

        return new ResolvedConfiguration(
            definitionPublicId: $definition->public_id,
            key: $definition->key,
            type: $definition->type,
            versionPublicId: $version->public_id,
            versionNumber: $version->version_number,
            value: $version->value,
            effectiveFrom: $version->effective_from,
            effectiveTo: $version->effective_to,
        );
    }
}
