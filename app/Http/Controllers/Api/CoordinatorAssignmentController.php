<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCoordinatorAssignmentRequest;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoordinatorAssignmentController extends Controller
{

    public function store(StoreCoordinatorAssignmentRequest $request): JsonResponse
    {
        $distributor = User::where('public_id', $request->distributor_public_id)->firstOrFail();
        $coordinator = User::where('public_id', $request->coordinator_public_id)->firstOrFail();
        $branch = Branch::where('public_id', $request->branch_public_id)->firstOrFail();

        $assignment = CoordinatorDistributorAssignment::create([
            'distributor_id'      => $distributor->id,
            'coordinator_user_id' => $coordinator->id,
            'branch_id'           => $branch->id,
            'starts_at'           => $request->starts_at,
            'ends_at'             => $request->ends_at,
            'assigned_by'         => $coordinator->id, 
            'source_type'         => 'MANUAL',
            'source_id'           => 1, 
            'reason'              => $request->reason,
        ]);

        return response()->json([
            'message' => 'Asignación creada correctamente',
            'data'    => $assignment 
        ], 201);
    }

    public function index(): JsonResponse
    {

        $assignments = CoordinatorDistributorAssignment::with(['distributor', 'coordinator', 'branch'])
            ->paginate(15); 

        return response()->json($assignments);
    }

    public function show(string $uuid): JsonResponse
    {
        $assignment = CoordinatorDistributorAssignment::with(['distributor', 'coordinator', 'branch'])
            ->where('public_id', $uuid)
            ->firstOrFail();

        return response()->json(['data' => $assignment]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'ends_at' => 'nullable|date',
            'reason'  => 'nullable|string|max:255'
        ]);

        $assignment = CoordinatorDistributorAssignment::where('public_id', $uuid)->firstOrFail();
        $assignment->update($validated);

        return response()->json([
            'message' => 'Asignación actualizada correctamente',
            'data'    => $assignment
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $assignment = CoordinatorDistributorAssignment::where('public_id', $uuid)->firstOrFail();
        $assignment->delete();

        return response()->json([
            'message' => 'Asignación eliminada correctamente'
        ]);
    }
}