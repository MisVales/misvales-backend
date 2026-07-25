<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Configurations;

use App\Modules\Configuration\Application\DTOs\PublishConfigurationVersionData;
use App\Modules\Configuration\Application\Services\ConfigurationValueValidator;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\ConfigurationVersionPublished;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentConfigurationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Publica una versión de configuración (C03).
 *
 * 1. Valida que la versión sea un borrador.
 * 2. Valida nuevamente tipo, valor, motivo e inicio de vigencia.
 * 3. Comprueba que la vigencia no sea retroactiva.
 * 4. Comprueba que no exista superposición.
 * 5. Cierra la vigencia anterior si aplica.
 * 6. Registra auditoría y evento dentro de la misma transacción.
 */
final class PublishConfigurationVersionUseCase
{
    public function __construct(
        private readonly EloquentConfigurationRepository $repository,
        private readonly ConfigurationValueValidator $validator,
    ) {}

    /**
     * @throws ConfigurationException Si la versión no es editable, la fecha es retroactiva o existe superposición.
     */
    public function execute(PublishConfigurationVersionData $data): ConfigurationVersionModel
    {
        return DB::transaction(function () use ($data): ConfigurationVersionModel {
            $version = $this->repository->lockVersion($data->versionPublicId);

            if ($version === null) {
                throw ConfigurationException::notFound();
            }

            if ($version->versionStatus() !== VersionStatus::DRAFT) {
                throw ConfigurationException::immutable();
            }

            $definition = $version->definition()->lockForUpdate()->firstOrFail();
            $key = $definition->configurationKey();

            // Re-validar valor
            $this->validator->validate($key, $version->value);

            $now = CarbonImmutable::now();

            // No permitir publicación retroactiva
            if ($data->effectiveFrom->lessThan($now)) {
                throw ConfigurationException::retroactivePublicationForbidden();
            }

            // Cerrar la vigencia anterior si existe (y obtenerla para excluirla de la regla de superposición)
            $currentVersion = $this->repository->resolveAt($definition, $data->effectiveFrom);
            
            $excludeIds = [$version->id];
            if ($currentVersion !== null) {
                $excludeIds[] = $currentVersion->id;
            }

            // Verificar no superposición
            if ($this->repository->hasOverlap($definition, $data->effectiveFrom, null, $excludeIds)) {
                throw ConfigurationException::versionOverlap();
            }

            if ($currentVersion !== null && $currentVersion->id !== $version->id) {
                $currentVersion->effective_to = $data->effectiveFrom;
                $currentVersion->save();
            }

            $correlationId = (string) Str::uuid();

            // Publicar
            $version->status = VersionStatus::PUBLISHED->value;
            $version->effective_from = $data->effectiveFrom;
            $version->published_by = $data->actorUserId;
            $version->published_at = $now;
            $version->reason = $data->reason;
            $version->save();

            // Auditoría
            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'CONFIGURATION_VERSION_PUBLISHED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'configuration_version',
                'resource_id' => $version->public_id,
                'configuration_key' => $key->value,
                'before_state' => ['status' => VersionStatus::DRAFT->value],
                'after_state' => ['status' => VersionStatus::PUBLISHED->value, 'effective_from' => $data->effectiveFrom->toIso8601String()],
                'status_before' => VersionStatus::DRAFT->value,
                'status_after' => VersionStatus::PUBLISHED->value,
                'version_after' => (string) $version->version_number,
                'effective_from' => $data->effectiveFrom,
                'reason' => $data->reason,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            ConfigurationVersionPublished::dispatch(
                $definition->public_id,
                $version->public_id,
                $key->value,
                $version->version_number,
                $data->effectiveFrom->toIso8601String(),
                (string) $data->actorUserId,
                $now->toIso8601String(),
            );

            return $version->fresh();
        });
    }
}
