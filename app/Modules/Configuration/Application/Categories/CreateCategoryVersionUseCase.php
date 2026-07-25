<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Categories;

use App\Modules\Configuration\Application\DTOs\CreateCategoryVersionData;
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
 * Crea una nueva versión borrador para una categoría existente.
 */
final class CreateCategoryVersionUseCase
{
    public function __construct(
        private readonly EloquentCategoryRepository $repository,
    ) {}

    public function execute(CreateCategoryVersionData $data): CategoryVersionModel
    {
        $percentage = new Percentage($data->distributorProfitRate);

        return DB::transaction(function () use ($data, $percentage): CategoryVersionModel {
            $category = $this->repository->lockById($data->categoryPublicId);

            if ($category === null) {
                throw ConfigurationException::categoryNotFound();
            }

            if ($category->status === VersionStatus::INACTIVE->value) {
                throw ConfigurationException::categoryInactive();
            }

            $versionNumber = $this->repository->nextVersionNumber($category);
            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();

            $version = new CategoryVersionModel();
            $version->public_id = (string) Str::uuid();
            $version->category_id = $category->id;
            $version->version_number = $versionNumber;
            $version->name = $data->name;
            $version->description = $data->description;
            $version->distributor_profit_rate = $percentage->databaseValue();
            $version->status = VersionStatus::DRAFT->value;
            $version->created_by = $data->actorUserId;
            $version->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'CATEGORY_DRAFT_CREATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'category_version',
                'resource_id' => $version->public_id,
                'after_state' => [
                    'name' => $data->name,
                    'description' => $data->description,
                    'distributor_profit_rate' => $percentage->databaseValue(),
                    'version_number' => $versionNumber,
                ],
                'status_after' => VersionStatus::DRAFT->value,
                'version_after' => (string) $versionNumber,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            return $version;
        });
    }
}
