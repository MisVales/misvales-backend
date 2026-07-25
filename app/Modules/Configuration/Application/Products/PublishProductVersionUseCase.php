<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Products;

use App\Modules\Configuration\Application\DTOs\PublishProductVersionData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\ProductVersionPublished;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentProductRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Publica una versión de producto (C09).
 */
final class PublishProductVersionUseCase
{
    public function __construct(
        private readonly EloquentProductRepository $repository,
    ) {}

    public function execute(PublishProductVersionData $data): ProductVersionModel
    {
        return DB::transaction(function () use ($data): ProductVersionModel {
            $version = $this->repository->lockVersion($data->versionPublicId);

            if ($version === null) {
                throw ConfigurationException::productNotFound();
            }

            if ($version->versionStatus() !== VersionStatus::DRAFT) {
                throw ConfigurationException::immutable();
            }

            $product = $version->product()->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if ($data->effectiveFrom->lessThan($now)) {
                throw ConfigurationException::retroactivePublicationForbidden();
            }

            if ($this->repository->hasOverlap($product, $data->effectiveFrom, null, $version->id)) {
                throw ConfigurationException::productVersionOverlap();
            }

            $currentVersion = $this->repository->resolveAt($product, $data->effectiveFrom);
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

            // Si el producto estaba en DRAFT, actualizar estado
            if ($product->status === VersionStatus::DRAFT->value) {
                $product->status = VersionStatus::PUBLISHED->value;
                $product->save();
            }

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'PRODUCT_VERSION_PUBLISHED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'product_version',
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

            ProductVersionPublished::dispatch(
                $product->public_id,
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
