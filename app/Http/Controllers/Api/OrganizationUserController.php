<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserOrganizationalResource;
use App\Models\User;
use Illuminate\Http\Request;

class OrganizationUserController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        $actor->load('role');

        $allowedRoleCodes = ['GENERAL_MANAGER', 'ADMINISTRATOR', 'BRANCH_MANAGER', 'COORDINATOR'];

        $roleCode = $actor->role->code ?? null;

        if ($roleCode instanceof \BackedEnum) {
            $roleCode = $roleCode->value;
        } elseif (is_object($roleCode) && enum_exists(get_class($roleCode))) {
            $roleCode = $roleCode->value ?? (string) $roleCode;
        } else {
            $roleCode = (string) $roleCode;
        }

        if (! in_array($roleCode, $allowedRoleCodes)) {
            return response()->json([
                'error' => [
                    'code' => 'ORGANIZATION_SCOPE_DENIED',
                    'message' => 'Tu perfil no tiene acceso al directorio general de usuarios.',
                ],
            ], 403);
        }

        $query = User::with(['role', 'branch']);

        if ($actor->role->scope === 'BRANCH') {
            $query->where('branch_id', $actor->branch_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        return UserOrganizationalResource::collection($query->paginate(15));
    }

    public function show($identifier, Request $request)
    {
        $actor = $request->user();
        $actor->load('role');

        $targetUser = User::with(['role', 'branch'])->where('public_id', $identifier)->firstOrFail();

        if ($actor->role->scope === 'BRANCH') {
            if ($actor->id !== $targetUser->id && $actor->branch_id !== $targetUser->branch_id) {
                return response()->json([
                    'error' => [
                        'code' => 'ORGANIZATION_SCOPE_DENIED',
                        'message' => 'El usuario solicitado no pertenece a tu alcance organizacional.',
                    ],
                ], 403);
            }
        }

        return new UserOrganizationalResource($targetUser);
    }
}
