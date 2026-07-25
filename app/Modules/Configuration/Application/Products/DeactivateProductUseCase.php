<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Products;

use App\Modules\Configuration\Application\DTOs\DeactivateProductData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\ProductDeactivated;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentProductRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Desactiva un producto por completo.
 */
final class DeactivateProductUseCase
{
    public function __construct(
        private readonly EloquentProductRepository $repository,
    ) {}

    public function execute(DeactivateProductData $data): ProductModel
    {
        return DB::transaction(function () use ($data): ProductModel {
            $product = $this->repository->lockById($data->productPublicId);

            if ($product === null) {
                throw ConfigurationException::productNotFound();
            }

            if ($product->status === VersionStatus::INACTIVE->value) {
                return $product; // Idempotente
            }

            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();
            $previousStatus = $product->status;

            $product->status = VersionStatus::INACTIVE->value;
            $product->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'PRODUCT_DEACTIVATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'product',
                'resource_id' => $product->public_id,
                'status_before' => $previousStatus,
                'status_after' => VersionStatus::INACTIVE->value,
                'reason' => $data->reason,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            ProductDeactivated::dispatch(
                $product->public_id,
                (string) $data->actorUserId,
                $data->reason,
                $now->toIso8601String(),
            );

            return $product;
        });
    }
}
