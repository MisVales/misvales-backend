<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCoordinatorAssignmentRequest;
use App\Models\CoordinatorDistributorAssignment;
use App\Exceptions\BusinessRuleException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests; 
use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch; 
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoordinatorAssignmentController extends Controller
{
    use AuthorizesRequests;

    /**
     * @OA\Post(
     *     path="/api/coordinator-assignments",
     *     summary="Asigna un coordinador a una distribuidora",
     *     description="Crea una asignación explícita verificando que ambos pertenezcan a la misma sucursal. Emite un evento de dominio post-commit (O12).",
     *     tags={"Asignaciones (M02)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"branch_public_id", "distributor_public_id", "coordinator_public_id", "starts_at"},
     *             @OA\Property(property="branch_public_id", type="string", format="uuid"),
     *             @OA\Property(property="distributor_public_id", type="string", format="uuid"),
     *             @OA\Property(property="coordinator_public_id", type="string", format="uuid"),
     *             @OA\Property(property="starts_at", type="string", format="date", example="2024-05-01"),
     *             @OA\Property(property="ends_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="reason", type="string", example="Asignación manual por reestructuración")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Asignación creada correctamente"),
     *     @OA\Response(response=422, description="COORDINATOR_BRANCH_MISMATCH - Sucursales no coinciden"),
     *     @OA\Response(response=403, description="Acceso denegado")
     * )
     */
    public function store(StoreCoordinatorAssignmentRequest $request): JsonResponse
    {
        $branch = Branch::where('public_id', $request->branch_public_id)->firstOrFail();

        $this->authorize('create', [CoordinatorDistributorAssignment::class, $branch]);

        $distributor = User::where('public_id', $request->distributor_public_id)->firstOrFail();
        $coordinator = User::where('public_id', $request->coordinator_public_id)->firstOrFail();

        // Aplicación estricta de Excepción de Dominio (O11)
        if ($distributor->branch_id !== $branch->id || $coordinator->branch_id !== $branch->id) {
            throw new BusinessRuleException(
                'COORDINATOR_BRANCH_MISMATCH',
                'El coordinador y la distribuidora deben pertenecer a la misma sucursal.',
                422
            );
        }

        DB::beginTransaction();
        try {
            $assignment = CoordinatorDistributorAssignment::create([
                'distributor_id'      => $distributor->id,
                'coordinator_user_id' => $coordinator->id,
                'branch_id'           => $branch->id,
                'starts_at'           => $request->starts_at,
                'ends_at'             => $request->ends_at,
                'assigned_by'         => auth()->id(), 
                'source_type'         => 'MANUAL',
                'source_id'           => 1, 
                'reason'              => $request->reason,
            ]);

            DB::afterCommit(function () use ($assignment) {
                event(new \App\Events\CoordinatorDistributorAssigned($assignment));
            });

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return response()->json([
            'message' => 'Asignación creada correctamente',
            'data'    => $assignment 
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/coordinator-assignments",
     *     summary="Lista las asignaciones de coordinadores",
     *     description="Obtiene las asignaciones vigentes filtradas automáticamente por el alcance organizacional (O04) del usuario.",
     *     tags={"Asignaciones (M02)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Listado de asignaciones paginadas")
     * )
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $role = $user->role->code ?? '';

        if ($role instanceof \BackedEnum) {
            $role = $role->value;
        }

        $query = CoordinatorDistributorAssignment::with(['distributor', 'coordinator', 'branch']);
        
        if ($role === 'BRANCH_MANAGER') {
            $query->where('branch_id', $user->branch_id);
        } 
        elseif ($role === 'COORDINATOR') {
            $query->where('coordinator_user_id', $user->id);
        }
        $assignments = $query->paginate(15);

        return response()->json($assignments);
    }

    /**
     * @OA\Get(
     *     path="/api/coordinator-assignments/{uuid}",
     *     summary="Consulta el detalle de una asignación",
     *     tags={"Asignaciones (M02)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="UUID público de la asignación",
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(response=200, description="Detalle de la asignación"),
     *     @OA\Response(response=403, description="Acceso denegado"),
     *     @OA\Response(response=404, description="Asignación no encontrada")
     * )
     */
    public function show(string $uuid): JsonResponse
    {
        $assignment = CoordinatorDistributorAssignment::with(['distributor', 'coordinator', 'branch'])
            ->where('public_id', $uuid)
            ->firstOrFail();
            
        $this->authorize('view', $assignment);

        return response()->json(['data' => $assignment]);
    }

    /**
     * @OA\Put(
     *     path="/api/coordinator-assignments/{uuid}",
     *     summary="Actualiza una asignación vigente",
     *     description="Permite modificar la fecha de fin o la razón de una asignación.",
     *     tags={"Asignaciones (M02)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="ends_at", type="string", format="date", nullable=true),
     *             @OA\Property(property="reason", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Asignación actualizada correctamente"),
     *     @OA\Response(response=403, description="Acceso denegado")
     * )
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'ends_at' => 'nullable|date',
            'reason'  => 'nullable|string|max:255'
        ]);

        $assignment = CoordinatorDistributorAssignment::where('public_id', $uuid)->firstOrFail();
        
        $this->authorize('update', $assignment);

        DB::beginTransaction();
        try {
            $assignment->update($validated);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return response()->json([
            'message' => 'Asignación actualizada correctamente',
            'data'    => $assignment
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/coordinator-assignments/{uuid}",
     *     summary="Elimina una asignación",
     *     tags={"Asignaciones (M02)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(response=200, description="Asignación eliminada correctamente"),
     *     @OA\Response(response=403, description="Acceso denegado")
     * )
     */
    public function destroy(string $uuid): JsonResponse
    {
        $assignment = CoordinatorDistributorAssignment::where('public_id', $uuid)->firstOrFail();
        
        $this->authorize('delete', $assignment);

        $assignment->delete();

        return response()->json([
            'message' => 'Asignación eliminada correctamente'
        ]);
    }
}