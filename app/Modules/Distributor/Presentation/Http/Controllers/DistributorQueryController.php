<?php

namespace App\Modules\Distributor\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Distributor\Application\Queries\GetDistributorCapabilitiesQuery;
use App\Modules\Distributor\Application\Queries\GetDistributorCategoryAssignmentsQuery;
use App\Modules\Distributor\Application\Queries\GetDistributorDetailQuery;
use App\Modules\Distributor\Application\Queries\ListDistributorsQuery;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Distributor\Presentation\Http\Resources\DistributorAdminDetailResource;
use App\Modules\Distributor\Presentation\Http\Resources\DistributorCapabilityResource;
use App\Modules\Distributor\Presentation\Http\Resources\DistributorCategoryAssignmentResource;
use Illuminate\Http\Request;

/**
 * Controlador administrativo de consultas del módulo Distribuidoras.
 * Resuelve listas, detalles, historiales y capabilities.
 */
class DistributorQueryController extends Controller
{
    /**
     * Lista de distribuidoras con soporte de filtros por sucursal y estado.
     *
     * @tags Distributor Query
     * @param Request $request
     * @param ListDistributorsQuery $query
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request, ListDistributorsQuery $query)
    {
        $this->authorize('viewAny', Distributor::class);

        $filters = $request->only(['branch_id', 'status', 'distributor_number']);
        // Se puede inyectar scope según el usuario activo, etc.

        $result = $query->execute($filters, $request->input('per_page', 15));

        return DistributorAdminDetailResource::collection($result);
    }

    public function show(string $id, GetDistributorDetailQuery $query)
    {
        $distributor = $query->execute($id);
        $this->authorize('view', $distributor);

        return new DistributorAdminDetailResource($distributor);
    }

    public function categoryAssignments(string $id, GetDistributorCategoryAssignmentsQuery $query)
    {
        $distributor = Distributor::findOrFail($id);
        $this->authorize('viewHistory', $distributor);

        $result = $query->execute($id, request('per_page', 15));

        return DistributorCategoryAssignmentResource::collection($result);
    }

    public function capabilities(string $id, GetDistributorCapabilitiesQuery $query)
    {
        $distributor = Distributor::findOrFail($id);
        $this->authorize('view', $distributor);

        $capabilities = $query->execute($id);

        return new DistributorCapabilityResource($capabilities);
    }
}
