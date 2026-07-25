<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Categories;

use App\Modules\Configuration\Application\DTOs\DeactivateCategoryData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\CategoryDeactivated;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentCategoryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Desactiva una categoría por completo.
 */
final class DeactivateCategoryUseCase
{
    public function __construct(
        private readonly EloquentCategoryRepository $repository,
    ) {}

    public function execute(DeactivateCategoryData $data): CategoryModel
    {
        return DB::transaction(function () use ($data): CategoryModel {
            $category = $this->repository->lockById($data->categoryPublicId);

            if ($category === null) {
                throw ConfigurationException::categoryNotFound();
            }

            if ($category->status === VersionStatus::INACTIVE->value) {
                return $category; // Idempotente
            }

            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();
            $previousStatus = $category->status;

            $category->status = VersionStatus::INACTIVE->value;
            $category->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'CATEGORY_DEACTIVATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'category',
                'resource_id' => $category->public_id,
                'status_before' => $previousStatus,
                'status_after' => VersionStatus::INACTIVE->value,
                'reason' => $data->reason,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            CategoryDeactivated::dispatch(
                $category->public_id,
                (string) $data->actorUserId,
                $data->reason,
                $now->toIso8601String(),
            );

            return $category;
        });
    }
}
