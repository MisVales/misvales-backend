<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Persistence\Repositories;

use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductVersionModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Repositorio Eloquent para productos financieros.
 */
final class EloquentProductRepository
{
    public function findById(string $publicId): ?ProductModel
    {
        return ProductModel::query()
            ->where('public_id', $publicId)
            ->first();
    }

    public function lockById(string $publicId): ?ProductModel
    {
        return ProductModel::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
    }

    public function findVersion(string $publicId): ?ProductVersionModel
    {
        return ProductVersionModel::query()
            ->where('public_id', $publicId)
            ->first();
    }

    public function lockVersion(string $publicId): ?ProductVersionModel
    {
        return ProductVersionModel::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Resuelve la versión publicada vigente para un producto en una fecha dada.
     */
    public function resolveAt(ProductModel $product, CarbonImmutable $effectiveDate): ?ProductVersionModel
    {
        return ProductVersionModel::query()
            ->where('product_id', $product->id)
            ->where('status', VersionStatus::PUBLISHED->value)
            ->where('effective_from', '<=', $effectiveDate)
            ->where(function ($q) use ($effectiveDate): void {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>', $effectiveDate);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    public function nextVersionNumber(ProductModel $product): int
    {
        $max = ProductVersionModel::query()
            ->where('product_id', $product->id)
            ->max('version_number');

        return ($max ?? 0) + 1;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return LengthAwarePaginator<ProductModel>
     */
    public function listProducts(array $filters = [], int $perPage = 15, bool $includeDrafts = false): LengthAwarePaginator
    {
        $query = ProductModel::query()->orderBy('created_at', 'desc');

        if (! $includeDrafts) {
            $query->where('status', '!=', VersionStatus::DRAFT->value);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return LengthAwarePaginator<ProductVersionModel>
     */
    public function listVersions(ProductModel $product, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ProductVersionModel::query()
            ->where('product_id', $product->id)
            ->orderByDesc('version_number');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Verifica si existe superposición de vigencia con versiones publicadas.
     */
    public function hasOverlap(
        ProductModel $product,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo,
        ?int $excludeVersionId = null,
    ): bool {
        $query = ProductVersionModel::query()
            ->where('product_id', $product->id)
            ->where('status', VersionStatus::PUBLISHED->value)
            ->where('effective_from', '<', $effectiveTo ?? CarbonImmutable::create(9999, 12, 31))
            ->where(function ($q) use ($effectiveFrom): void {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>', $effectiveFrom);
            });

        if ($excludeVersionId !== null) {
            $query->where('id', '!=', $excludeVersionId);
        }

        return $query->exists();
    }
}
