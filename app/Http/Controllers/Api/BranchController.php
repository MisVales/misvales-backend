<?php

namespace App\Http\Controllers\Api;

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

    public function show(string $uuid): JsonResponse
    {
        $branch = Branch::where('public_id', $uuid)->firstOrFail();
        $user = auth()->user();
        $role = $this->normalizeRole($user->role->code ?? '');

        $restrictedRoles = ['BRANCH_MANAGER', 'COORDINATOR', 'VERIFIER', 'CASHIER', 'DISTRIBUTOR'];

        if (in_array($role, $restrictedRoles, true)) {
            if ($user->branch_id !== $branch->id) {
                return response()->json([
                    'error' => [
                        'code' => 'ORGANIZATION_SCOPE_DENIED',
                        'message' => 'No tienes permiso para consultar esta sucursal.'
                    ]
                ], 403);
            }
        }

        return response()->json(['data' => $branch]);
    }
}