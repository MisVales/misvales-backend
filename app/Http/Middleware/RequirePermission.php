<?php

namespace App\Http\Middleware;

use App\Services\Audit\SecurityAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $request->user() || ! $request->user()->hasPermissionTo($permission)) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'No tiene el permiso requerido para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
