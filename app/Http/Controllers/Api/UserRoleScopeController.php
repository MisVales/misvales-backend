<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRoleScopeRequest;
use App\Models\UserRoleScope;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class UserRoleScopeController extends Controller
{
    public function store(StoreUserRoleScopeRequest $request): JsonResponse
    {
        $this->authorize('create', UserRoleScope::class);

        $user = User::where('public_id', $request->user_public_id)->firstOrFail();
        
        $branchId = null;
        if ($request->branch_public_id) {
            $branchId = Branch::where('public_id', $request->branch_public_id)->firstOrFail()->id;
        }

        $scope = UserRoleScope::create([
            'user_id'    => $user->id,
            'role_id'    => $request->role_id,
            'branch_id'  => $branchId,
            'scope_type' => $request->scope_type,
        ]);

        return response()->json([
            'message' => 'Alcance de rol asignado correctamente',
            'data'    => $scope
        ], 201);
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', UserRoleScope::class);

        $user = auth()->user();
        $role = $user->role->code ?? '';

        $query = UserRoleScope::with(['user', 'role', 'branch']);

        if ($role === 'BRANCH_MANAGER') {
            $query->where('branch_id', $user->branch_id);
        }

        $scopes = $query->paginate(15);

        return response()->json($scopes);
    }
}