<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Configurations;

use App\Modules\Configuration\Application\DTOs\EditConfigurationVersionData;
use App\Modules\Configuration\Application\Services\ConfigurationValueValidator;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentConfigurationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Edita un borrador de configuración (C03).
 *
 * - Solo se edita un DRAFT.
 * - Se requiere lock_version para control de concurrencia.
 * - El valor completo se vuelve a validar.
 */
final class EditConfigurationVersionUseCase
{
    public function __construct(
        private readonly EloquentConfigurationRepository $repository,
        private readonly ConfigurationValueValidator $validator,
    ) {}

    /**
     * @throws ConfigurationException Si la versión no es DRAFT, el lock_version no coincide o el valor es inválido.
     */
    public function execute(EditConfigurationVersionData $data): ConfigurationVersionModel
    {
        return DB::transaction(function () use ($data): ConfigurationVersionModel {
            $version = $this->repository->lockVersion($data->versionPublicId);

            if ($version === null) {
                throw ConfigurationException::notFound();
            }

            if ($version->versionStatus() !== VersionStatus::DRAFT) {
                throw ConfigurationException::immutable();
            }

            if ($version->lock_version !== $data->lockVersion) {
                throw ConfigurationException::versionConflict();
            }

            $definition = $version->definition;
            $key = $definition->configurationKey();

            // Re-validar valor completo
            $this->validator->validate($key, $data->value);

            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();
            $beforeValue = $version->value;

            $version->value = $data->value;
            $version->lock_version = $version->lock_version + 1;
            $version->save();

            // Auditoría con antes y después
            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'CONFIGURATION_DRAFT_EDITED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'configuration_version',
                'resource_id' => $version->public_id,
                'configuration_key' => $key->value,
                'before_state' => ['value' => $beforeValue],
                'after_state' => ['value' => $data->value],
                'status_before' => VersionStatus::DRAFT->value,
                'status_after' => VersionStatus::DRAFT->value,
                'version_after' => (string) $version->version_number,
                'correlation_id' => $correlationId,
                'request_id' => (string) Str::uuid(),
                'occurred_at' => $now,
            ]);

            return $version;
        });
    }
}
