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
            if ($request->is('api/v1/distributor-applications*')) {
                try {
                    $solicitud = $request->route('application');
                    $actorRole = $request->user()?->roleScopes()
                        ->select('roles.code')
                        ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
                        ->where('user_role_scopes.status', 'ACTIVE')
                        ->whereNull('user_role_scopes.revoked_at')
                        ->value('roles.code');
                    app(SecurityAuditService::class)->log($request, [
                        'branch_id' => is_object($solicitud) ? $solicitud->branch_id : null,
                        'event_type' => 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
                        'severity' => 'WARNING',
                        'outcome' => 'DENIED',
                        'entity_type' => 'distributor_application',
                        'entity_id' => is_string($solicitud) ? $solicitud : $solicitud?->id,
                        'metadata' => [
                            'actor_role' => $actorRole,
                            'application_id' => is_string($solicitud) ? $solicitud : $solicitud?->id,
                            'application_number' => is_object($solicitud) ? $solicitud->application_number : null,
                            'action' => $request->method(),
                            'previous_values' => [],
                            'new_values' => [],
                            'reason' => "Missing permission: {$permission}",
                            'path' => $request->path(),
                            'result' => 'DENIED',
                        ],
                    ]);
                } catch (\Throwable) {
                    // La auditoría nunca debe ocultar la respuesta de autorización.
                }

                return response()->json([
                    'error' => [
                        'code' => 'AUTH_SCOPE_DENIED',
                        'message' => 'No tiene permiso para realizar esta acción.',
                        'fields' => (object) [],
                        'details' => (object) [],
                        'request_id' => $request->attributes->get('request_id'),
                    ],
                ], 403);
            }

            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'No tiene el permiso requerido para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
