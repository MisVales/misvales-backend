<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Categories;

use App\Modules\Configuration\Application\DTOs\CreateCategoryData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\CategoryCreated;
use App\Modules\Configuration\Domain\ValueObjects\Percentage;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea una categoría con su primer borrador.
 */
final class CreateCategoryUseCase
{
    public function execute(CreateCategoryData $data): CategoryVersionModel
    {
        // Validar porcentaje
        $percentage = new Percentage($data->distributorProfitRate);

        return DB::transaction(function () use ($data, $percentage): CategoryVersionModel {
            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();

            // Identidad base
            $category = new CategoryModel;
            $category->public_id = (string) Str::uuid();
            $category->status = VersionStatus::DRAFT->value;
            $category->created_by = $data->actorUserId;
            $category->save();

            // Primer borrador
            $version = new CategoryVersionModel;
            $version->public_id = (string) Str::uuid();
            $version->category_id = $category->id;
            $version->version_number = 1;
            $version->name = $data->name;
            $version->description = $data->description;
            $version->distributor_profit_rate = $percentage->databaseValue();
            $version->status = VersionStatus::DRAFT->value;
            $version->created_by = $data->actorUserId;
            $version->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'CATEGORY_CREATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'category',
                'resource_id' => $category->public_id,
                'after_state' => [
                    'name' => $data->name,
                    'description' => $data->description,
                    'distributor_profit_rate' => $percentage->databaseValue(),
                ],
                'status_after' => VersionStatus::DRAFT->value,
                'version_after' => '1',
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            CategoryCreated::dispatch(
                $category->public_id,
                $version->public_id,
                1,
                (string) $data->actorUserId,
                $now->toIso8601String(),
            );

            return $version;
        });
    }
}
