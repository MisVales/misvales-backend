<?php

namespace App\Http\Middleware;

use App\Models\UserRoleScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBranchScope
{
    /**
     * Valida que si la URL incluye un ID de sucursal (ej: /api/v1/branches/{branch}/sales)
     * el usuario autenticado tenga jurisdicción sobre ella.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $solicitud = $request->route('application');
        $branchId = $solicitud instanceof SolicitudDistribuidora
            ? $solicitud->branch_id
            : ($request->route('branch') ?? $request->input('branch_id'));

        if ($branchId && $request->user()) {
            $hasScope = UserRoleScope::where('user_id', $request->user()->id)
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->where(function ($query) use ($branchId) {
                    $query->whereNull('branch_id') // Acceso Global
                        ->orWhere('branch_id', $branchId); // Acceso Específico
                })->exists();

            if (! $hasScope) {
                return response()->json([
                    'error' => 'SCOPE_DENIED',
                    'message' => 'La sucursal solicitada no está dentro de su alcance autorizado.',
                ], 403);
            }
        }

        return $next($request);
    }
}
