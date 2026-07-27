<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRoleScopeRequest;
use App\Models\UserRoleScope;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UserRoleScopeController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreUserRoleScopeRequest $request): JsonResponse
    {
        $this->authorize('create', UserRoleScope::class);

        $user = User::where('public_id', $request->user_public_id)->firstOrFail();
        
        $branchId = null;
        if ($request->branch_public_id) {
            $branchId = Branch::where('public_id', $request->branch_public_id)->firstOrFail()->id;
        }

        DB::beginTransaction();
        try {
            $scope = UserRoleScope::create([
                'user_id'    => $user->id,
                'role_id'    => $request->role_id,
                'branch_id'  => $branchId,
                'scope_type' => $request->scope_type,
            ]);

            DB::afterCommit(function () use ($scope) {
                event(new \App\Events\UserOrganizationalScopeAssigned($scope));
            });

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

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

        if ($role instanceof \BackedEnum) {
            $role = $role->value;
        }

        $query = UserRoleScope::with(['user', 'role', 'branch']);

        if ($role === 'BRANCH_MANAGER') {
            $query->where('branch_id', $user->branch_id);
        }

        $scopes = $query->paginate(15);

        return response()->json($scopes);
    }
}