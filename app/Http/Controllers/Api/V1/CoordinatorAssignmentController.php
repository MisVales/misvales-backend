<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CoordinatorAssignmentController extends Controller
{
    /**
     * View assignments for a branch.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', CoordinatorDistributorAssignment::class);

        $request->validate([
            'branch_id' => 'required|uuid|exists:branches,id',
        ]);

        $branchId = $request->query('branch_id');

        // Check if user has scope for this branch
        if (!$request->user()->hasScopeForBranch($branchId)) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $assignments = CoordinatorDistributorAssignment::with(['coordinator', 'assignedBy', 'endedBy'])
            ->where('branch_id', $branchId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($assignments);
    }

    /**
     * Assign a distributor to a coordinator.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'coordinator_id' => 'required|uuid|exists:users,id',
            'distributor_id' => 'required|uuid', // Note: assuming distributor is a uuid, wait for module 4/5 for strict FK
            'branch_id' => 'required|uuid|exists:branches,id',
            'assignment_reason' => 'nullable|string',
        ]);

        Gate::authorize('manage', [CoordinatorDistributorAssignment::class, $validated['branch_id']]);

        // Verify coordinator has the 'coordinator' role in this branch
        $isCoordinator = UserRoleScope::where('user_id', $validated['coordinator_id'])
            ->whereHas('role', function ($q) {
                $q->where('code', 'coordinator');
            })
            ->where('branch_id', $validated['branch_id'])
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->exists();

        if (!$isCoordinator) {
            return response()->json(['message' => 'El usuario especificado no es un coordinador activo en esta sucursal.'], 400);
        }

        return DB::transaction(function () use ($request, $validated) {
            // Find if distributor already has an active assignment
            $activeAssignment = CoordinatorDistributorAssignment::where('distributor_id', $validated['distributor_id'])
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->first();

            if ($activeAssignment) {
                if ($activeAssignment->coordinator_id === $validated['coordinator_id']) {
                    return response()->json(['message' => 'Esta distribuidora ya está asignada a este coordinador.'], 200);
                }

                // End previous assignment (Reassignment)
                $activeAssignment->status = 'REASSIGNED';
                $activeAssignment->valid_to = now();
                $activeAssignment->ended_by = $request->user()->id;
                $activeAssignment->end_reason = 'Reasignación a otro coordinador';
                $activeAssignment->lock_version++;
                $activeAssignment->save();

                app(SecurityAuditService::class)->log($request, [
                    'event_type' => 'COORDINATOR_ASSIGNMENT_ENDED',
                    'severity' => 'INFO',
                    'outcome' => 'SUCCESS',
                    'entity_type' => 'CoordinatorDistributorAssignment',
                    'entity_id' => $activeAssignment->id,
                    'metadata' => ['reason' => 'Reassignment'],
                ]);
            }

            // Create new assignment
            $newAssignment = CoordinatorDistributorAssignment::create([
                'id' => Str::uuid(),
                'coordinator_id' => $validated['coordinator_id'],
                'distributor_id' => $validated['distributor_id'],
                'branch_id' => $validated['branch_id'],
                'valid_from' => now(),
                'status' => 'ACTIVE',
                'assigned_by' => $request->user()->id,
                'assignment_reason' => $validated['assignment_reason'],
            ]);

            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'COORDINATOR_ASSIGNMENT_CREATED',
                'severity' => 'INFO',
                'outcome' => 'SUCCESS',
                'entity_type' => 'CoordinatorDistributorAssignment',
                'entity_id' => $newAssignment->id,
                'metadata' => ['distributor_id' => $validated['distributor_id']],
            ]);

            return response()->json($newAssignment->load(['coordinator']), 201);
        });
    }

    /**
     * End an assignment without deleting history.
     */
    public function destroy(Request $request, CoordinatorDistributorAssignment $assignment)
    {
        Gate::authorize('manage', [CoordinatorDistributorAssignment::class, $assignment->branch_id]);

        if ($assignment->status !== 'ACTIVE' || $assignment->valid_to !== null) {
            return response()->json(['message' => 'La asignación ya está inactiva.'], 400);
        }

        $validated = $request->validate([
            'end_reason' => 'required|string',
        ]);

        $assignment->status = 'ENDED';
        $assignment->valid_to = now();
        $assignment->ended_by = $request->user()->id;
        $assignment->end_reason = $validated['end_reason'];
        $assignment->lock_version++;
        $assignment->save();

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'COORDINATOR_ASSIGNMENT_ENDED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'CoordinatorDistributorAssignment',
            'entity_id' => $assignment->id,
        ]);

        return response()->json(['message' => 'Asignación terminada.', 'assignment' => $assignment]);
    }
}
