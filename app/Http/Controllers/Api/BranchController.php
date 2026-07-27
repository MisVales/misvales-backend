<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $role = $user->role->code ?? '';

        $query = Branch::query();

        if ($role === 'BRANCH_MANAGER' || $role === 'COORDINATOR' || $role === 'VERIFIER' || $role === 'CASHIER' || $role === 'DISTRIBUTOR') {
            $query->where('id', $user->branch_id);
        }

        $branches = $query->paginate(15);

        return response()->json($branches);
    }

    public function show(string $uuid): JsonResponse
    {
        $branch = Branch::where('public_id', $uuid)->firstOrFail();
        $user = auth()->user();
        $role = $user->role->code ?? '';

        if (in_array($role, ['BRANCH_MANAGER', 'COORDINATOR', 'VERIFIER', 'CASHIER', 'DISTRIBUTOR'])) {
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