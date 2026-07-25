<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Categories;

use App\Modules\Configuration\Application\DTOs\PublishCategoryVersionData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\CategoryVersionPublished;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentCategoryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Publica una versión de categoría.
 */
final class PublishCategoryVersionUseCase
{
    public function __construct(
        private readonly EloquentCategoryRepository $repository,
    ) {}

    public function execute(PublishCategoryVersionData $data): CategoryVersionModel
    {
        return DB::transaction(function () use ($data): CategoryVersionModel {
            $version = $this->repository->lockVersion($data->versionPublicId);

            if ($version === null) {
                throw ConfigurationException::categoryNotFound();
            }

            if ($version->versionStatus() !== VersionStatus::DRAFT) {
                throw ConfigurationException::immutable();
            }

            $category = $version->category()->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if ($data->effectiveFrom->lessThan($now)) {
                throw ConfigurationException::retroactivePublicationForbidden();
            }

            if ($this->repository->hasOverlap($category, $data->effectiveFrom, null, $version->id)) {
                throw ConfigurationException::categoryVersionOverlap();
            }

            $currentVersion = $this->repository->resolveAt($category, $data->effectiveFrom);
            if ($currentVersion !== null && $currentVersion->id !== $version->id) {
                $currentVersion->effective_to = $data->effectiveFrom;
                $currentVersion->save();
            }

            $correlationId = (string) Str::uuid();

            $version->status = VersionStatus::PUBLISHED->value;
            $version->effective_from = $data->effectiveFrom;
            $version->published_by = $data->actorUserId;
            $version->published_at = $now;
            $version->reason = $data->reason;
            $version->save();

            // Si la categoría estaba en DRAFT, actualizar estado
            if ($category->status === VersionStatus::DRAFT->value) {
                $category->status = VersionStatus::PUBLISHED->value;
                $category->save();
            }

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'CATEGORY_VERSION_PUBLISHED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'category_version',
                'resource_id' => $version->public_id,
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

            CategoryVersionPublished::dispatch(
                $category->public_id,
                $version->public_id,
                $version->version_number,
                $data->effectiveFrom->toIso8601String(),
                (string) $data->actorUserId,
                $now->toIso8601String(),
            );

            return $version->fresh();
        });
    }
}
