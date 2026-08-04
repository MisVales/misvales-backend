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
        if (!$request->user() || !$request->user()->hasPermissionTo($permission)) {
            $user = $request->user();
            $scope = $user ? $user->roleScopes()->first() : null;
            
            \App\Models\AuditLog::create([
                'actor_id' => $user?->id,
                'actor_role' => $scope ? $scope->role->name : 'GUEST',
                'branch_id' => null, // Omitimos para evitar fallos si no tiene scope
                'entity_type' => 'Auth',
                'event_name' => 'Acción rechazada por autorización.',
                'entity_id' => null,
                'version' => null,
                'previous_value' => null,
                'new_value' => null,
                'reason' => "Se denegó la acción. Falta permiso requerido: {$permission}",
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_id' => $request->attributes->get('request_id') ?? $request->header('X-Request-Id'),
                'result' => 'FAILED',
            ]);

            return response()->json([
                'error' => 'AUTH_SCOPE_DENIED',
                'message' => 'No tiene el permiso requerido para realizar esta acción.'
            ], 403);
        }

        return $next($request);
    }
}
