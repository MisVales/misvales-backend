<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Queries\GetRelation;

use App\Modules\Relation\Infrastructure\Persistence\Models\Relation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class GetRelationQuery
{
    public function handle(string $relationId): Relation
    {
        return Relation::with(['snapshot', 'items', 'documents'])->findOrFail($relationId);
    }
}
