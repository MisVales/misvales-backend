<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\Audit\SecurityAuditService;
use App\Services\Auth\RoleAssignmentPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class BranchPersonnelController extends Controller
{
    /**
     * View personnel assigned to a branch.
     */
    public function index(Request $request, Branch $branch)
    {
        // Require permission to view personnel (roles.assign or branches.view + jurisdiction)
        Gate::authorize('view', $branch);

        $personnel = UserRoleScope::with(['user', 'role'])
            ->where('branch_id', $branch->id)
            ->where('status', 'ACTIVE')
            ->whereNull('valid_to')
            ->get();

        return response()->json($personnel);
    }

    /**
     * Assign personnel to a branch.
     */
    public function store(Request $request, Branch $branch, RoleAssignmentPolicyService $policyService)
    {
        Gate::authorize('managePersonnel', $branch);

        $validated = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'role_code' => 'required|string|exists:roles,code',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $role = Role::where('code', $validated['role_code'])->firstOrFail();

        // Use RoleAssignmentPolicyService to validate assignment rules
        $policyService = app(RoleAssignmentPolicyService::class);
        if (!method_exists($policyService, 'validateAssignment')) {
            throw new \Exception('validateAssignment method does not exist on ' . get_class($policyService));
        }

        $validationResult = $policyService->validateAssignment(
            $request->user(),
            $user,
            $role,
            $branch->id
        );

        if ($validationResult !== true) {
            return response()->json(['message' => 'Error al asignar rol: ' . $validationResult], 403);
        }

        return DB::transaction(function () use ($request, $branch, $user, $role) {
            // End any existing assignment for this user with this role in this branch
            $existing = UserRoleScope::where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->where('branch_id', $branch->id)
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->first();

            if ($existing) {
                // If identical active assignment exists, do nothing
                return response()->json(['message' => 'El usuario ya tiene este rol asignado en la sucursal.'], 200);
            }

            $assignment = UserRoleScope::create([
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'branch_id' => $branch->id,
                'assigned_by' => $request->user()->id,
                'valid_from' => now(),
                'scope_type' => 'BRANCH',
                'status' => 'ACTIVE',
            ]);

            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'BRANCH_PERSONNEL_ASSIGNED',
                'severity' => 'INFO',
                'outcome' => 'SUCCESS',
                'entity_type' => 'UserRoleScope',
                'entity_id' => $assignment->id,
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'metadata' => ['role_code' => $role->code],
            ]);

            return response()->json($assignment->load(['user', 'role']), 201);
        });
    }

    /**
     * Withdraw assignment without deleting history.
     */
    public function destroy(Request $request, Branch $branch, UserRoleScope $assignment)
    {
        Gate::authorize('managePersonnel', $branch);

        if ($assignment->branch_id !== $branch->id) {
            return response()->json(['message' => 'La asignación no pertenece a esta sucursal.'], 403);
        }

        if ($assignment->status !== 'ACTIVE' || $assignment->valid_to !== null) {
            return response()->json(['message' => 'La asignación ya está terminada o revocada.'], 400);
        }

        $assignment->status = 'ENDED';
        $assignment->valid_to = now();
        $assignment->ended_by = $request->user()->id;
        $assignment->reason = $request->input('reason', 'Asignación retirada');
        $assignment->save();

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'BRANCH_PERSONNEL_REMOVED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'UserRoleScope',
            'entity_id' => $assignment->id,
            'user_id' => $assignment->user_id,
            'branch_id' => $branch->id,
        ]);

        return response()->json(['message' => 'Asignación terminada correctamente.', 'assignment' => $assignment]);
    }
}
