<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    private function normalizeRole($role): string
    {
        if ($role instanceof \BackedEnum) {
            return $role->value;
        }
        if (is_object($role) && method_exists($role, 'value')) {
            return $role->value;
        }

        return (string) $role;
    }

    /**
     * @OA\Get(
     *     path="/api/branches",
     *     summary="Lista las sucursales",
     *     description="Obtiene el listado de sucursales. Para roles de alcance local, solo devuelve la sucursal asignada al usuario.",
     *     tags={"Organización (M02)"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Listado paginado de sucursales"
     *     )
     * )
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $role = $this->normalizeRole($user->role->code ?? '');

        $query = Branch::query();

        $restrictedRoles = ['BRANCH_MANAGER', 'COORDINATOR', 'VERIFIER', 'CASHIER', 'DISTRIBUTOR'];

        if (in_array($role, $restrictedRoles, true)) {
            $query->where('id', $user->branch_id);
        }

        $branches = $query->paginate(15);

        return response()->json($branches);
    }

    /**
     * @OA\Get(
     *     path="/api/branches/{uuid}",
     *     summary="Consulta el detalle de una sucursal",
     *     description="Devuelve la información de una sucursal específica. Si un usuario con rol de alcance local intenta consultar una sucursal distinta a la suya, se simula un error 404 por seguridad (O11).",
     *     tags={"Organización (M02)"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="UUID público de la sucursal",
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Detalle de la sucursal"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="BRANCH_NOT_FOUND - La sucursal no existe dentro del alcance visible"
     *     )
     * )
     */
    public function show(string $uuid): JsonResponse
    {
        $branch = Branch::where('public_id', $uuid)->firstOrFail();
        $user = auth()->user();
        $role = $this->normalizeRole($user->role->code ?? '');

        $restrictedRoles = ['BRANCH_MANAGER', 'COORDINATOR', 'VERIFIER', 'CASHIER', 'DISTRIBUTOR'];

        if (in_array($role, $restrictedRoles, true)) {
            if ($user->branch_id !== $branch->id) {
                // Aplicación estricta O11: No revelar que la sucursal existe
                throw new BusinessRuleException(
                    'BRANCH_NOT_FOUND',
                    'La sucursal no existe dentro del alcance visible.',
                    404
                );
            }
        }

        return response()->json(['data' => $branch]);
    }
}
