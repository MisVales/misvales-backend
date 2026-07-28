<?php

namespace App\Http\Controllers\Api;

use App\Events\RolePermissionsChanged;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Modules\Access\Domain\Contracts\OrganizationContextInvalidatorInterface;
use App\Modules\Access\Infrastructure\Persistence\Models\Permission;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/roles",
     *     summary="Lista los roles del sistema",
     *     description="Obtiene el catálogo de roles disponibles en la plataforma.",
     *     tags={"Roles y Permisos (M02)"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Response(response=200, description="Listado de roles obtenido correctamente")
     * )
     */
    public function index(): JsonResponse
    {
        $roles = Role::all();

        return response()->json(['data' => $roles]);
    }

    /**
     * @OA\Get(
     *     path="/api/roles/{id}",
     *     summary="Obtiene el detalle de un rol",
     *     description="Devuelve la información de un rol junto con todos los permisos que tiene asignados actualmente.",
     *     tags={"Roles y Permisos (M02)"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID del rol",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(response=200, description="Detalle del rol y sus permisos"),
     *     @OA\Response(response=404, description="Rol no encontrado")
     * )
     */
    public function show($id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json(['data' => $role]);
    }

    /**
     * @OA\Put(
     *     path="/api/roles/{id}/permissions",
     *     summary="Actualiza los permisos de un rol",
     *     description="Asigna una nueva matriz de permisos a un rol y dispara la invalidación de contexto (O08) para los usuarios afectados. Solo accesible por el Gerente General.",
     *     tags={"Roles y Permisos (M02)"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID del rol a modificar",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"permissions", "reason"},
     *
     *             @OA\Property(property="permissions", type="array", @OA\Items(type="string"), example={"VIEW_DASHBOARD", "CREATE_USER"}),
     *             @OA\Property(property="reason", type="string", example="Actualización trimestral de políticas de seguridad")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Permisos actualizados correctamente"),
     *     @OA\Response(response=403, description="ORGANIZATION_SCOPE_DENIED - Solo el Gerente General puede modificar permisos globales."),
     *     @OA\Response(response=404, description="Rol no encontrado")
     * )
     */
    public function updatePermissions(
        Request $request,
        $id,
        OrganizationContextInvalidatorInterface $invalidator
    ): JsonResponse {
        $actor = $request->user();
        $actor->load('role');

        $roleCode = $actor->role->code ?? null;

        if ($roleCode instanceof \BackedEnum) {
            $roleCode = $roleCode->value;
        } elseif (is_object($roleCode) && enum_exists(get_class($roleCode))) {
            $roleCode = $roleCode->value ?? (string) $roleCode;
        } else {
            $roleCode = (string) $roleCode;
        }

        // Aplicación estricta de Excepción de Dominio (O11)
        if ($roleCode !== 'GENERAL_MANAGER') {
            throw new BusinessRuleException(
                'ORGANIZATION_SCOPE_DENIED',
                'Solo el Gerente General puede modificar permisos globales.',
                403
            );
        }

        $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => 'exists:permissions,code',
            'reason' => 'required|string|max:255',
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
                event(new RolePermissionsChanged(
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
                    'permissions_assigned' => $request->permissions,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
