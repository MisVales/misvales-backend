<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Queries\ListRelations;

use App\Modules\Relation\Infrastructure\Persistence\Models\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

class ListRelationsQuery
{
    public function handle(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Relation::with('snapshot');

        if (isset($filters['distributor_id'])) {
            $query->where('distributor_id', $filters['distributor_id']);
        }
        
        // Appends more filters as required by specification...
        
        return $query->orderBy('cut_date', 'desc')->paginate($perPage);
    }
}
