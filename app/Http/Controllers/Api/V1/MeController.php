<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * GET /api/v1/me
     * Devuelve la identidad del usuario, alcances y permisos efectivos.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        // Cargar los alcances (scopes) con sus respectivos roles y permisos
        $user->load(['roleScopes' => function ($query) {
            // Solo alcances activos y no vencidos
            $query->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
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

            $rolePermissions = $scope->role->permissions->pluck('code')->toArray();

            $scopes[] = [
                'role' => $scope->role->code,
                'role_name' => $scope->role->name,
                'branch_id' => $scope->branch_id,
                'permissions' => $rolePermissions,
            ];

            // Ir acumulando permisos globales únicos
            $effectivePermissions = array_merge($effectivePermissions, $rolePermissions);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'state' => $user->state,
            ],
            'scopes' => $scopes,
            'effective_permissions' => array_values(array_unique($effectivePermissions)),
        ]);
    }
}
