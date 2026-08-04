<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PermissionController extends Controller
{
    /**
     * GET /api/v1/permissions
     * Punto 34: Lista todos los permisos del sistema
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Permission::class);

        return response()->json(Permission::all());
    }
}
