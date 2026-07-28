<?php

declare(strict_types=1);

namespace App\Modules\Relation\Interfaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Relation\Application\Queries\GetRelation\GetRelationQuery;
use App\Modules\Relation\Application\Queries\ListRelations\ListRelationsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelationController extends Controller
{
    public function index(Request $request, ListRelationsQuery $query): JsonResponse
    {
        $filters = $request->only([
            'distributor_id', 'cut_date', 'branch_id', 'coordinator_id',
            'financial_status', 'payment_behavior', 'under_review',
        ]);

        $relations = $query->handle($filters, (int) $request->get('per_page', 15));

        return response()->json($relations);
    }

    public function show(string $id, GetRelationQuery $query): JsonResponse
    {
        $relation = $query->handle($id);

        return response()->json($relation);
    }
}
