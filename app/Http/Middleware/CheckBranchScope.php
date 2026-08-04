<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\UserRoleScope;

class CheckBranchScope
{
    /**
     * Valida que si la URL incluye un ID de sucursal (ej: /api/v1/branches/{branch}/sales)
     * el usuario autenticado tenga jurisdicción sobre ella.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Se asume que el parámetro en la ruta se llama 'branch' o se pasa 'branch_id' en el body
        $branchId = $request->route('branch') ?? $request->input('branch_id');

        if ($branchId && $request->user()) {
            $hasScope = UserRoleScope::where('user_id', $request->user()->id)
                ->whereNull('revoked_at')
                ->where(function ($query) use ($branchId) {
                    $query->whereNull('branch_id') // Acceso Global
                          ->orWhere('branch_id', $branchId); // Acceso Específico
                })->exists();

            if (!$hasScope) {
                return response()->json([
                    'error' => 'SCOPE_DENIED',
                    'message' => 'La sucursal solicitada no está dentro de su alcance autorizado.'
                ], 403);
            }
        }

        return $next($request);
    }
}
