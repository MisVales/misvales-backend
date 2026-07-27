<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Access\Domain\Contracts\OrganizationContextInvalidatorInterface;
use App\Modules\Access\Infrastructure\Persistence\Models\Permission;
use App\Modules\Access\Infrastructure\Persistence\Models\Role; 
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::all();
        return response()->json(['data' => $roles]);
    }

    public function show($id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);
        return response()->json(['data' => $role]);
    }

    public function updatePermissions(
        Request $request, 
        $id, 
        OrganizationContextInvalidatorInterface $invalidator
    ): JsonResponse
    {
        $actor = $request->user();
        $actor->load('role');

        $roleCode = $actor->role->code ?? null;
        
        if ($roleCode instanceof \BackedEnum) {
            $roleCode = $roleCode->value;
        } elseif (is_object($roleCode) && enum_exists(get_class($roleCode))) {
            $roleCode = $roleCode->value ?? (string)$roleCode;
        } else {
            $roleCode = (string) $roleCode;
        }

        if ($roleCode !== 'GENERAL_MANAGER') {
            return response()->json([
                'error' => [
                    'code' => 'ORGANIZATION_SCOPE_DENIED',
                    'message' => 'Solo el Gerente General puede modificar permisos globales.'
                ]
            ], 403);
        }

        $request->validate([
            'permissions'   => 'present|array',
            'permissions.*' => 'exists:permissions,code',
            'reason'        => 'required|string|max:255'
        ]);

        $role = Role::findOrFail($id);

        DB::beginTransaction();
        try {
            $permissionIds = Permission::whereIn('code', $request->permissions)->pluck('id');
            $role->permissions()->sync($permissionIds);

            // Invalidación limpia mediante el Contrato / Servicio (O08)
            $invalidator->invalidateForRole(
                $role->id, 
                "Actualización de permisos globales: {$request->reason}"
            );

            DB::afterCommit(function () use ($role, $request) {
                event(new \App\Events\RolePermissionsChanged(
                    $role, 
                    $request->permissions, 
                    $request->reason
                ));
            });

            DB::commit();

            return response()->json([
                'message' => 'Permisos actualizados correctamente.',
                'data' => [
                    'role' => $role->name,
                    'permissions_assigned' => $request->permissions
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}