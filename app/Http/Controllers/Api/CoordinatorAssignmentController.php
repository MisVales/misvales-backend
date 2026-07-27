<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCoordinatorAssignmentRequest;
use App\Models\CoordinatorDistributorAssignment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests; 
use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch; 
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoordinatorAssignmentController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreCoordinatorAssignmentRequest $request): JsonResponse
    {
        $branch = Branch::where('public_id', $request->branch_public_id)->firstOrFail();

        $this->authorize('create', [CoordinatorDistributorAssignment::class, $branch]);

        $distributor = User::where('public_id', $request->distributor_public_id)->firstOrFail();
        $coordinator = User::where('public_id', $request->coordinator_public_id)->firstOrFail();

        if ($distributor->branch_id !== $branch->id || $coordinator->branch_id !== $branch->id) {
            return response()->json([
                'error' => [
                    'code' => 'COORDINATOR_BRANCH_MISMATCH',
                    'message' => 'El coordinador y la distribuidora deben pertenecer a la misma sucursal.'
                ]
            ], 422);
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

    public function show(string $uuid): JsonResponse
    {
        $assignment = CoordinatorDistributorAssignment::with(['distributor', 'coordinator', 'branch'])
            ->where('public_id', $uuid)
            ->firstOrFail();
            
        $this->authorize('view', $assignment);

        return response()->json(['data' => $assignment]);
    }

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