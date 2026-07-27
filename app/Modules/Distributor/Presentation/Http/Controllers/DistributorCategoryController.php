<?php

namespace App\Modules\Distributor\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Distributor\Application\Categories\AssignCategoryHandler;
use App\Modules\Distributor\Application\Categories\ChangeCategoryHandler;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Distributor\Persistence\Models\DistributorCategoryAssignment;
use App\Modules\Distributor\Presentation\Http\Requests\AssignCategoryRequest;
use Illuminate\Http\JsonResponse;

class DistributorCategoryController extends Controller
{
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
