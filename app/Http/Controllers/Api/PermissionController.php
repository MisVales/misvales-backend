<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Access\Infrastructure\Persistence\Models\Permission;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        // En un escenario real, podríamos filtrar qué permisos son visibles,
        // pero por ahora devolvemos el catálogo completo estandarizado.
        $permissions = Permission::select('id', 'code', 'name')->get();

        return response()->json([
            'data' => $permissions,
        ]);
    }
}
