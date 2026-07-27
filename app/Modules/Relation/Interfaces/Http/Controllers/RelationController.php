<?php

declare(strict_types=1);

namespace App\Modules\Relation\Interfaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Relation\Infrastructure\Persistence\Models\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelationController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // TODO: Apply authorization policies (e.g. $this->authorize('viewAny', Relation::class))
        // TODO: Apply filters (cut_date, branch, coordinator, distributor, financial_status, etc.)
        
        $relations = Relation::with('snapshot')
            ->orderBy('cut_date', 'desc')
            ->paginate((int) $request->get('per_page', 15));

        return response()->json($relations);
    }

    /**
     * @param Relation $relation
     * @return JsonResponse
     */
    public function show(Relation $relation): JsonResponse
    {
        // $this->authorize('view', $relation);
        $relation->load(['snapshot', 'items', 'documents']);
        
        return response()->json($relation);
    }
}
