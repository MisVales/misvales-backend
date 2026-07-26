<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Persistence\Repositories;

use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryVersionModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Repositorio Eloquent para categorías de distribuidora.
 */
final class EloquentCategoryRepository
{
    public function findById(string $publicId): ?CategoryModel
    {
        return CategoryModel::query()
            ->where('public_id', $publicId)
            ->first();
    }

    public function lockById(string $publicId): ?CategoryModel
    {
        return CategoryModel::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
    }

    public function findVersion(string $publicId): ?CategoryVersionModel
    {
        return CategoryVersionModel::query()
            ->where('public_id', $publicId)
            ->first();
    }

    public function lockVersion(string $publicId): ?CategoryVersionModel
    {
        return CategoryVersionModel::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Resuelve la versión publicada vigente para una categoría en una fecha dada.
     */
    public function resolveAt(CategoryModel $category, CarbonImmutable $effectiveDate): ?CategoryVersionModel
    {
        return CategoryVersionModel::query()
            ->where('category_id', $category->id)
            ->where('status', VersionStatus::PUBLISHED->value)
            ->where('effective_from', '<=', $effectiveDate)
            ->where(function ($q) use ($effectiveDate): void {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $effectiveDate);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    public function nextVersionNumber(CategoryModel $category): int
    {
        $max = CategoryVersionModel::query()
            ->where('category_id', $category->id)
            ->max('version_number');

        return ($max ?? 0) + 1;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CategoryModel>
     */
    public function listCategories(array $filters = [], int $perPage = 15, bool $includeDrafts = false): LengthAwarePaginator
    {
        $query = CategoryModel::query()->orderBy('created_at', 'desc');

        if (! $includeDrafts) {
            $query->where('status', '!=', VersionStatus::DRAFT->value);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CategoryVersionModel>
     */
    public function listVersions(CategoryModel $category, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = CategoryVersionModel::query()
            ->where('category_id', $category->id)
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
        CategoryModel $category,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo,
        ?int $excludeVersionId = null,
    ): bool {
        $query = CategoryVersionModel::query()
            ->where('category_id', $category->id)
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
