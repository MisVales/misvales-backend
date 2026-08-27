<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveVpnContext;
use App\Modules\Organization\Domain\Assignments\Exceptions\RoleScopeNotAllowed;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationAssignmentRules;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MeController extends Controller
{
    /**
     * GET /api/v1/me
     * Devuelve la identidad del usuario, alcances y permisos efectivos.
     */
    public function show(Request $request, OrganizationAssignmentRules $assignmentRules)
    {
        $user = $request->user();

        // Cargar los alcances (scopes) con sus respectivos roles y permisos
        $user->load(['roleScopes' => function ($query) {
            // Solo alcances activos y no vencidos
            $query->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->with('branch:id,name,code')
                ->with(['role' => function ($roleQuery) {
                    $roleQuery->with('permissions');
                }]);
        }]);

        $scopes = [];
        $effectivePermissions = [];

        foreach ($user->roleScopes as $scope) {
            if (! $scope->role) {
                continue;
            }

            try {
                $organizationScope = OrganizationScope::fromString($scope->scope_type);
                $assignmentRules->assertRoleAllowsScope($scope->role->code, $organizationScope);
            } catch (InvalidArgumentException|RoleScopeNotAllowed) {
                // Una asignación estructuralmente inválida no concede
                // capacidades efectivas ni se anuncia al cliente.
                continue;
            }

            if (($organizationScope === OrganizationScope::BRANCH && $scope->branch_id === null)
                || ($organizationScope === OrganizationScope::GLOBAL && $scope->branch_id !== null)
                || ($organizationScope === OrganizationScope::DISTRIBUTOR
                    && ($scope->branch_id === null || $scope->scope_id === null))) {
                continue;
            }

            $rolePermissions = $scope->role->permissions->pluck('code')->toArray();

            $scopes[] = [
                'role' => $scope->role->code,
                'role_name' => $scope->role->name,
                'branch_id' => $scope->branch_id,
                'branch_name' => $scope->branch?->name,
                'branch_code' => $scope->branch?->code,
                'scope_type' => $scope->scope_type,
                'scope_id' => $scope->scope_id,
                'permissions' => $rolePermissions,
            ];

            // Ir acumulando permisos globales únicos
            $effectivePermissions = array_merge($effectivePermissions, $rolePermissions);
        }

        $vpn = (bool) $request->attributes->get(ResolveVpnContext::ATTRIBUTE, false);
        $isManager = collect($scopes)->contains(
            fn (array $scope): bool => in_array($scope['role'], ['general_manager', 'branch_manager'], true)
        );

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'state' => $user->state,
            ],
            'scopes' => $scopes,
            'effective_permissions' => array_values(array_unique($effectivePermissions)),
            'access_context' => ['vpn' => $vpn],
            'capabilities' => ['manager_actions' => $isManager && $vpn],
        ]);
    }
}
