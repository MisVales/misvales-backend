<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::all();

        return response()->json(['data' => $roles]);
    }
    public function show(int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        return response()->json(['data' => $role]);
    }
}