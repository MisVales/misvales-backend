<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Persistence\Repositories;

use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationDefinitionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Repositorio Eloquent para configuraciones globales.
 *
 * Concentra las consultas de versión vigente, bloqueo pesimista
 * y asignación de número consecutivo de versión.
 */
final class EloquentConfigurationRepository
{
    /**
     * Busca la definición por clave estable.
     */
    public function findDefinitionByKey(ConfigurationKey $key): ?ConfigurationDefinitionModel
    {
        return ConfigurationDefinitionModel::query()
            ->where('key', $key->value)
            ->first();
    }

    /**
     * Busca la definición por clave con bloqueo pesimista.
     */
    public function lockDefinitionByKey(ConfigurationKey $key): ?ConfigurationDefinitionModel
    {
        return ConfigurationDefinitionModel::query()
            ->where('key', $key->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Busca una versión por su public_id.
     */
    public function findVersion(string $publicId): ?ConfigurationVersionModel
    {
        return ConfigurationVersionModel::query()
            ->where('public_id', $publicId)
            ->first();
    }

    /**
     * Busca una versión con bloqueo pesimista.
     */
    public function lockVersion(string $publicId): ?ConfigurationVersionModel
    {
        return ConfigurationVersionModel::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Resuelve la versión publicada vigente en una fecha dada.
     */
    public function resolveAt(ConfigurationDefinitionModel $definition, CarbonImmutable $effectiveDate): ?ConfigurationVersionModel
    {
        return ConfigurationVersionModel::query()
            ->where('definition_id', $definition->id)
            ->where('status', VersionStatus::PUBLISHED->value)
            ->where('effective_from', '<=', $effectiveDate)
            ->where(function ($q) use ($effectiveDate): void {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>', $effectiveDate);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Obtiene el siguiente número de versión para una definición.
     */
    public function nextVersionNumber(ConfigurationDefinitionModel $definition): int
    {
        $max = ConfigurationVersionModel::query()
            ->where('definition_id', $definition->id)
            ->max('version_number');

        return ($max ?? 0) + 1;
    }

    /**
     * Lista definiciones con paginación.
     *
     * @param array<string, mixed> $filters
     *
     * @return LengthAwarePaginator<ConfigurationDefinitionModel>
     */
    public function listDefinitions(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ConfigurationDefinitionModel::query()->orderBy('key');

        if (isset($filters['key'])) {
            $query->where('key', $filters['key']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Lista versiones de una definición con paginación.
     *
     * @param array<string, mixed> $filters
     *
     * @return LengthAwarePaginator<ConfigurationVersionModel>
     */
    public function listVersions(ConfigurationDefinitionModel $definition, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ConfigurationVersionModel::query()
            ->where('definition_id', $definition->id)
            ->orderByDesc('version_number');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Verifica si existe superposición de vigencia con versiones publicadas.
     *
     * @param array<int> $excludeVersionIds
     */
    public function hasOverlap(
        ConfigurationDefinitionModel $definition,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo = null,
        array $excludeVersionIds = [],
    ): bool {
        $query = ConfigurationVersionModel::query()
            ->where('definition_id', $definition->id)
            ->where('status', VersionStatus::PUBLISHED->value)
            ->where('effective_from', '<', $effectiveTo ?? CarbonImmutable::create(9999, 12, 31))
            ->where(function ($q) use ($effectiveFrom): void {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>', $effectiveFrom);
            });

        if (!empty($excludeVersionIds)) {
            $query->whereNotIn('id', $excludeVersionIds);
        }

        return $query->exists();
    }
}
