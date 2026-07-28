<?php

namespace App\Modules\Distributor\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Distributor\Application\Categories\AssignCategoryHandler;
use App\Modules\Distributor\Application\Categories\ChangeCategoryHandler;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Distributor\Persistence\Models\DistributorCategoryAssignment;
use App\Modules\Distributor\Presentation\Http\Requests\AssignCategoryRequest;
use Illuminate\Http\JsonResponse;

/**
 * Controlador para la gestión y asignación de categorías de una Distribuidora.
 * Encargado de exponer la creación o actualización de asignaciones históricas de categoría.
 */
class DistributorCategoryController extends Controller
{
    /**
     * Asigna o actualiza la categoría de una distribuidora procesando de manera idempotente.
     *
     * @tags Distributor
     * @param string $id UUID de la distribuidora.
     * @param AssignCategoryRequest $request
     * @param AssignCategoryHandler $assignHandler
     * @param ChangeCategoryHandler $changeHandler
     * @return JsonResponse
     */
    public function store(
        string $id, 
        AssignCategoryRequest $request, 
        AssignCategoryHandler $assignHandler,
        ChangeCategoryHandler $changeHandler
    ): JsonResponse {
        $distributor = Distributor::findOrFail($id);

        // Se usa viewHistory como ejemplo para permisos de lectura antes de acción,
        // En policy real debería ser assignCategory
        $this->authorize('assignCategory', $distributor);

        $hasCategory = DistributorCategoryAssignment::where('distributor_id', $id)
            ->whereNull('effective_to')
            ->exists();

        // Extraer datos del actor (Mock)
        $assignedBy = request()->user()?->id ?? 'system';
        $assignedRole = 'MANAGER'; // Extraer de M02
        $assignedBranchId = $distributor->branch_id; // Mismo de la sucursal actual
        $idempotencyKey = $request->header('Idempotency-Key', uniqid('idem_', true));

        if ($hasCategory) {
            $result = $changeHandler->handle(
                $id,
                $request->validated('category_version_id'),
                $request->validated('reason'),
                $request->validated('lock_version'),
                $assignedBy,
                $assignedRole,
                $assignedBranchId,
                $idempotencyKey
            );
        } else {
            $result = $assignHandler->handle(
                $id,
                $request->validated('category_version_id'),
                $request->validated('reason'),
                $request->validated('lock_version'),
                $assignedBy,
                $assignedRole,
                $assignedBranchId,
                $idempotencyKey
            );
        }

        return response()->json(['data' => $result], 200);
    }
}
