<?php

declare(strict_types=1);

namespace App\Modules\Credit\Infrastructure\Persistence\Eloquent\Repositories;

use App\Modules\Credit\Domain\Repositories\CreditLineRepository;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;

final class EloquentCreditLineRepository implements CreditLineRepository
{
    public function findForDistributor(int $distributorId): ?CreditLineModel
    {
        return CreditLineModel::query()->where('distributor_id', $distributorId)->first();
    }

    public function lockForDistributor(int $distributorId): ?CreditLineModel
    {
        return CreditLineModel::query()
            ->where('distributor_id', $distributorId)
            ->lockForUpdate()
            ->first();
    }
}
