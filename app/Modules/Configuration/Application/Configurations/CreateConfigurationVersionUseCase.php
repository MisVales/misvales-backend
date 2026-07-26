<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Configurations;

use App\Modules\Configuration\Application\DTOs\CreateConfigurationVersionData;
use App\Modules\Configuration\Application\Services\ConfigurationValueValidator;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\ConfigurationDraftCreated;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentConfigurationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea una versión borrador de configuración (C03).
 *
 * El borrador no cambia la versión vigente.
 * El número de versión se asigna en backend.
 */
final class CreateConfigurationVersionUseCase
{
    public function __construct(
        private readonly EloquentConfigurationRepository $repository,
        private readonly ConfigurationValueValidator $validator,
    ) {}

    /**
     * @throws ConfigurationException Si la clave no existe, no es administrable o el valor es inválido.
     */
    public function execute(CreateConfigurationVersionData $data): ConfigurationVersionModel
    {
        $key = ConfigurationKey::tryFrom($data->key);

        if ($key === null) {
            throw ConfigurationException::notFound("La clave «{$data->key}» no es una configuración aprobada.");
        }

        if (! $key->isAdministrable()) {
            throw ConfigurationException::changeNotAllowed($data->key);
        }

        // Validar el valor conforme al tipo esperado
        $this->validator->validate($key, $data->value);

        return DB::transaction(function () use ($key, $data): ConfigurationVersionModel {
            $definition = $this->repository->lockDefinitionByKey($key);

            if ($definition === null) {
                throw ConfigurationException::notFound("La definición de «{$key->value}» no existe.");
            }

            $versionNumber = $this->repository->nextVersionNumber($definition);
            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();

            $version = new ConfigurationVersionModel;
            $version->public_id = (string) Str::uuid();
            $version->definition_id = $definition->id;
            $version->version_number = $versionNumber;
            $version->value = $data->value;
            $version->status = VersionStatus::DRAFT->value;
            $version->created_by = $data->actorUserId;
            $version->save();

            // Auditoría dentro de la misma transacción
            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'CONFIGURATION_DRAFT_CREATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'configuration_version',
                'resource_id' => $version->public_id,
                'configuration_key' => $key->value,
                'after_state' => ['value' => $data->value, 'version_number' => $versionNumber],
                'status_after' => VersionStatus::DRAFT->value,
                'version_after' => (string) $versionNumber,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            // Evento pendiente transaccional
            ConfigurationDraftCreated::dispatch(
                $definition->public_id,
                $version->public_id,
                $key->value,
                $versionNumber,
                (string) $data->actorUserId,
                $now->toIso8601String(),
            );

            return $version;
        });
    }
}
