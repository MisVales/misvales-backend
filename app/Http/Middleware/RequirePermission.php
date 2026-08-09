<?php

namespace App\Http\Middleware;

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
            if ($request->is('api/v1/distributors*') || $request->is('api/v1/distributor-applications/*/activation') || $request->is('api/v1/clients*')) {
                return response()->json(['error' => [
                    'code' => 'AUTH_SCOPE_DENIED',
                    'message' => 'No tiene permiso para realizar esta acción.',
                    'fields' => (object) [],
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ]], 403);
            }

            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'No tiene el permiso requerido para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
