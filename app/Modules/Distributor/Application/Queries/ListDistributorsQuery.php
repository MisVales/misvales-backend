<?php

namespace App\Modules\Distributor\Application\Queries;

use App\Modules\Distributor\Persistence\Models\Distributor;

class ListDistributorsQuery
{
    public function execute(array $filters, int $perPage = 15)
    {
        $query = Distributor::query();

        if (isset($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['distributor_number'])) {
            $query->where('distributor_number', $filters['distributor_number']);
        }

        // Apply ordering and limits...
        return $query->paginate($perPage);
    }
}
