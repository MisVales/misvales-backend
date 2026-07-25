<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Persistence\Repositories;

use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Repositorio Eloquent para periodos de canje.
 */
final class EloquentRedemptionPeriodRepository
{
    public function findById(string $publicId): ?RedemptionPeriodModel
    {
        return RedemptionPeriodModel::query()
            ->where('public_id', $publicId)
            ->first();
    }

    public function lockById(string $publicId): ?RedemptionPeriodModel
    {
        return RedemptionPeriodModel::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Resuelve el periodo de canje publicado y vigente en una fecha dada.
     */
    public function findActiveAt(CarbonImmutable $effectiveDate): ?RedemptionPeriodModel
    {
        return RedemptionPeriodModel::query()
            ->where('status', VersionStatus::PUBLISHED->value)
            ->where('starts_at', '<=', $effectiveDate)
            ->where('ends_at', '>', $effectiveDate)
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return LengthAwarePaginator<RedemptionPeriodModel>
     */
    public function listPeriods(array $filters = [], int $perPage = 15, bool $includeDrafts = false): LengthAwarePaginator
    {
        $query = RedemptionPeriodModel::query()->orderByDesc('starts_at');

        if (! $includeDrafts) {
            $query->where('status', '!=', VersionStatus::DRAFT->value);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }
}
