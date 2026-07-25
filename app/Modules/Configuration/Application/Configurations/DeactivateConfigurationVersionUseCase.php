<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Configurations;

use App\Modules\Configuration\Application\DTOs\DeactivateConfigurationVersionData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\ConfigurationVersionDeactivated;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentConfigurationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Desactiva una versión de configuración (C03).
 *
 * - Requiere motivo y reautenticación.
 * - No elimina la versión.
 * - No modifica operaciones que ya conservaron el valor.
 */
final class DeactivateConfigurationVersionUseCase
{
    public function __construct(
        private readonly EloquentConfigurationRepository $repository,
    ) {}

    public function execute(DeactivateConfigurationVersionData $data): ConfigurationVersionModel
    {
        return DB::transaction(function () use ($data): ConfigurationVersionModel {
            $version = $this->repository->lockVersion($data->versionPublicId);

            if ($version === null) {
                throw ConfigurationException::notFound();
            }

            if ($version->versionStatus() === VersionStatus::INACTIVE) {
                return $version; // Idempotente
            }

            $definition = $version->definition;
            $key = $definition->configurationKey();
            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();
            $previousStatus = $version->status;

            $version->status = VersionStatus::INACTIVE->value;
            if ($version->effective_to === null && $version->effective_from !== null) {
                $version->effective_to = $now;
            }
            $version->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'CONFIGURATION_VERSION_DEACTIVATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'configuration_version',
                'resource_id' => $version->public_id,
                'configuration_key' => $key->value,
                'status_before' => $previousStatus,
                'status_after' => VersionStatus::INACTIVE->value,
                'version_after' => (string) $version->version_number,
                'reason' => $data->reason,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            ConfigurationVersionDeactivated::dispatch(
                $definition->public_id,
                $version->public_id,
                $key->value,
                $version->version_number,
                (string) $data->actorUserId,
                $data->reason,
                $now->toIso8601String(),
            );

            return $version;
        });
    }
}
