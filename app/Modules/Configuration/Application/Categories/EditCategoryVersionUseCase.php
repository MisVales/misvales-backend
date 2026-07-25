<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Categories;

use App\Modules\Configuration\Application\DTOs\EditCategoryVersionData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\Percentage;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentCategoryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Edita un borrador de categoría.
 */
final class EditCategoryVersionUseCase
{
    public function __construct(
        private readonly EloquentCategoryRepository $repository,
    ) {}

    public function execute(EditCategoryVersionData $data): CategoryVersionModel
    {
        $percentage = new Percentage($data->distributorProfitRate);

        return DB::transaction(function () use ($data, $percentage): CategoryVersionModel {
            $version = $this->repository->lockVersion($data->versionPublicId);

            if ($version === null) {
                throw ConfigurationException::categoryNotFound();
            }

            if ($version->versionStatus() !== VersionStatus::DRAFT) {
                throw ConfigurationException::immutable();
            }

            if ($version->lock_version !== $data->lockVersion) {
                throw ConfigurationException::versionConflict();
            }

            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();

            $beforeState = [
                'name' => $version->name,
                'description' => $version->description,
                'distributor_profit_rate' => $version->distributor_profit_rate,
            ];

            $version->name = $data->name;
            $version->description = $data->description;
            $version->distributor_profit_rate = $percentage->databaseValue();
            $version->lock_version = $version->lock_version + 1;
            $version->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'CATEGORY_DRAFT_EDITED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'category_version',
                'resource_id' => $version->public_id,
                'before_state' => $beforeState,
                'after_state' => [
                    'name' => $data->name,
                    'description' => $data->description,
                    'distributor_profit_rate' => $percentage->databaseValue(),
                ],
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
