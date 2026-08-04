<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    /**
     * GET /api/v1/roles
     * Punto 34: Listar roles
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Role::class);

        return response()->json(Role::all());
    }

    /**
     * GET /api/v1/roles/{id}
     * Punto 34: Detalle de rol con sus permisos
     */
    public function show(Request $request, string $id)
    {
        $role = Role::with('permissions')->findOrFail($id);
        Gate::authorize('view', $role);

        return response()->json($role);
    }

    /**
     * PUT /api/v1/roles/{id}/permissions
     * Punto 34: Sincronizar permisos de un rol (bloqueado para roles del sistema)
     */
    public function syncPermissions(Request $request, string $id)
    {
        $role = Role::findOrFail($id);
        Gate::authorize('updatePermissions', $role);

        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'uuid|exists:permissions,id',
        ]);

        $role->permissions()->sync($request->permissions);

        return response()->json([
            'message' => 'Permisos sincronizados exitosamente.',
            'role' => $role->load('permissions')
        ]);
    }
}
