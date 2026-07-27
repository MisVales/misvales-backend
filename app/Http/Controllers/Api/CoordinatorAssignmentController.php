<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCoordinatorAssignmentRequest;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

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
}