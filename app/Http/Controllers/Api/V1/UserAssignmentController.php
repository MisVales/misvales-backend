<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ReauthenticatesMfa;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\Audit\SecurityAuditService;
use App\Services\Auth\RoleAssignmentPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UserAssignmentController extends Controller
{
    use ReauthenticatesMfa;

    /**
     * GET /api/v1/users/{id}/assignments
     * Punto 35: Listar asignaciones activas de un usuario
     */
    public function index(Request $request, string $userId)
    {
        Gate::authorize('viewAny', UserRoleScope::class);

        // Validar que el usuario existe
        User::findOrFail($userId);

        $assignments = UserRoleScope::with(['role', 'assignedBy'])
            ->where('user_id', $userId)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->get();

        return response()->json($assignments);
    }

    /**
     * POST /api/v1/users/{id}/assignments
     * Punto 35 y 36: Asignar un rol y sucursal a un usuario con validaciones profundas.
     */
    public function store(Request $request, string $userId, RoleAssignmentPolicyService $policyService)
    {
        Gate::authorize('create', UserRoleScope::class);

        $user = User::findOrFail($userId);

        return DB::transaction(function () use ($request, $user, $policyService) {
            $request->validate([
                'role_id' => 'required|uuid|exists:roles,id',
                'branch_id' => 'nullable|uuid', // Nullable significa alcance global
            ]);

            $role = Role::findOrFail($request->role_id);

            // Validación Avanzada (Punto 36)
            $validationResult = $policyService->validateAssignment(
                $request->user(),
                $user,
                $role,
                $request->branch_id
            );

            if ($validationResult !== true) {
                // If it's a string message rather than an exception
                abort(403, $validationResult);
            }

            // Evitar asignaciones duplicadas (mismo rol en la misma sucursal para el mismo usuario)
            $exists = UserRoleScope::where('user_id', $user->id)
                ->where('role_id', $request->role_id)
                ->where('branch_id', $request->branch_id)
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->exists();

            if ($exists) {
                abort(400, 'El usuario ya tiene este rol asignado en este alcance.');
            }

            $assignment = UserRoleScope::create([
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $request->role_id,
                'branch_id' => $request->branch_id,
                'assigned_by_user_id' => $request->user()->id,
                'assigned_at' => now(),
                'scope_type' => $request->branch_id ? 'BRANCH' : 'GLOBAL',
                'status' => 'ACTIVE',
            ]);

            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'ROLE_ASSIGNED',
                'severity' => 'INFO',
                'outcome' => 'SUCCESS',
                'entity_type' => 'UserRoleScope',
                'entity_id' => $assignment->id,
                'user_id' => $user->id,
                'branch_id' => $request->branch_id,
                'metadata' => ['role_id' => $request->role_id],
            ]);

            return response()->json([
                'message' => 'Asignación creada exitosamente.',
                'assignment' => $assignment->load('role'),
            ], 201);
        });
    }

    /**
     * DELETE /api/v1/users/{id}/assignments/{assignmentId}
     * Punto 35: Revocar una asignación (Soft-Delete para auditoría)
     */
    public function destroy(Request $request, string $userId, string $assignmentId)
    {
        $assignment = UserRoleScope::where('id', $assignmentId)
            ->where('user_id', $userId)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->firstOrFail();

        Gate::authorize('delete', $assignment);

        $reauthResult = $this->requireMfaReauth($request);
        if ($reauthResult !== true) {
            return $reauthResult;
        }

        $assignment->revoked_at = now();
        $assignment->revoked_by_user_id = $request->user()->id;
        $assignment->revocation_reason = 'REVOKED_BY_ADMIN';
        $assignment->status = 'REVOKED';
        $assignment->save();

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'ROLE_REVOKED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'entity_type' => 'UserRoleScope',
            'entity_id' => $assignment->id,
            'user_id' => $userId,
            'branch_id' => $assignment->branch_id,
        ]);

        return response()->json(['message' => 'Asignación revocada exitosamente.']);
    }
}
