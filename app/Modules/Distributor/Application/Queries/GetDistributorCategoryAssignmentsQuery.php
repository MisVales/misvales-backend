<?php

namespace App\Modules\Distributor\Application\Queries;

use App\Modules\Distributor\Persistence\Models\DistributorCategoryAssignment;

class GetDistributorCategoryAssignmentsQuery
{
    public function execute(string $distributorId, int $perPage = 15)
    {
        return DistributorCategoryAssignment::where('distributor_id', $distributorId)
            ->orderBy('effective_from', 'desc')
            ->paginate($perPage);
    }
}
