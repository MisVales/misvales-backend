<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Repositories;

use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;

interface CreditLineRepository
{
    public function findForDistributor(int $distributorId): ?CreditLineModel;

    public function lockForDistributor(int $distributorId): ?CreditLineModel;
}
