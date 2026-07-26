<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Resolution;

use App\Modules\Configuration\Application\DTOs\ResolvedConfiguration;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use Carbon\CarbonImmutable;

/**
 * Resuelve varias configuraciones con la misma fecha efectiva (C04).
 *
 * Cuando un cálculo necesita varias configuraciones relacionadas,
 * se resuelven usando la misma fecha efectiva para garantizar
 * un conjunto consistente.
 */
final class BulkConfigurationResolver
{
    public function __construct(
        private readonly ConfigurationResolver $resolver,
    ) {}

    /**
     * Resuelve un conjunto de configuraciones para una fecha efectiva.
     *
     * @param  ConfigurationKey[]  $keys  Claves a resolver.
     * @param  CarbonImmutable  $at  Fecha efectiva única.
     * @return array<string, ResolvedConfiguration> Indexado por clave.
     */
    public function resolveMany(array $keys, CarbonImmutable $at): array
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key->value] = $this->resolver->resolve($key, $at);
        }

        return $results;
    }
}
