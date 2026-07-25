<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Services;

use App\Modules\Configuration\Application\Contracts\RedemptionPeriodContract;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentRedemptionPeriodRepository;
use Carbon\CarbonImmutable;

/**
 * Implementación del contrato de periodo de canje (C07).
 */
final class RedemptionPeriodReadService implements RedemptionPeriodContract
{
    public function __construct(
        private readonly EloquentRedemptionPeriodRepository $repository,
    ) {}

    public function isRedemptionOpen(CarbonImmutable $at): bool
    {
        return $this->repository->findActiveAt($at) !== null;
    }

    /** @inheritDoc */
    public function getActivePeriod(CarbonImmutable $at): ?array
    {
        $period = $this->repository->findActiveAt($at);

        if ($period === null) {
            return null;
        }

        return [
            'period_id' => $period->public_id,
            'starts_at' => $period->starts_at->toIso8601String(),
            'ends_at' => $period->ends_at->toIso8601String(),
        ];
    }
}
