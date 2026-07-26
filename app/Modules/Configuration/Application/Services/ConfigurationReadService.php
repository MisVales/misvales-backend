<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Services;

use App\Modules\Configuration\Application\Contracts\ConfigurationReadContract;
use App\Modules\Configuration\Application\DTOs\ResolvedConfiguration;
use App\Modules\Configuration\Application\Resolution\BulkConfigurationResolver;
use App\Modules\Configuration\Application\Resolution\ConfigurationResolver;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use Carbon\CarbonImmutable;

/**
 * Implementación del contrato de lectura de configuraciones (C04).
 *
 * Envuelve los resolvers para exponerlos a módulos consumidores
 * mediante la interfaz ConfigurationReadContract.
 */
final class ConfigurationReadService implements ConfigurationReadContract
{
    public function __construct(
        private readonly ConfigurationResolver $resolver,
        private readonly BulkConfigurationResolver $bulkResolver,
    ) {}

    public function resolve(ConfigurationKey $key, CarbonImmutable $at): ResolvedConfiguration
    {
        return $this->resolver->resolve($key, $at);
    }

    /** {@inheritDoc} */
    public function resolveMany(array $keys, CarbonImmutable $at): array
    {
        return $this->bulkResolver->resolveMany($keys, $at);
    }
}
