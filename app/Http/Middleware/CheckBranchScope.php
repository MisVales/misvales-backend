<?php

namespace App\Http\Middleware;

use App\Models\SolicitudDistribuidora;
use App\Models\UserRoleScope;
use App\Services\Audit\SecurityAuditService;
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
                if ($request->is('api/v1/distributor-applications*')) {
                    $actorRole = UserRoleScope::query()
                        ->select('roles.code')
                        ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
                        ->where('user_role_scopes.user_id', $request->user()->id)
                        ->where('user_role_scopes.status', 'ACTIVE')
                        ->whereNull('user_role_scopes.revoked_at')
                        ->value('roles.code');

                    app(SecurityAuditService::class)->log($request, [
                        'actor_user_id' => $request->user()->id,
                        'branch_id' => $branchId,
                        'event_type' => 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
                        'severity' => 'WARNING',
                        'outcome' => 'DENIED',
                        'entity_type' => 'distributor_application',
                        'entity_id' => $solicitud instanceof SolicitudDistribuidora ? $solicitud->id : null,
                        'metadata' => [
                            'actor_role' => $actorRole,
                            'application_id' => $solicitud instanceof SolicitudDistribuidora ? $solicitud->id : null,
                            'application_number' => $solicitud instanceof SolicitudDistribuidora ? $solicitud->application_number : null,
                            'action' => $request->method(),
                            'previous_values' => [],
                            'new_values' => [],
                            'reason' => 'Branch scope denied.',
                            'result' => 'DENIED',
                        ],
                    ]);

                    return response()->json(['error' => [
                        'code' => 'AUTH_SCOPE_DENIED',
                        'message' => 'La sucursal solicitada no está dentro de su alcance autorizado.',
                        'fields' => (object) [],
                        'details' => (object) [],
                        'request_id' => $request->attributes->get('request_id'),
                    ]], 403);
                }

                return response()->json(['error' => 'SCOPE_DENIED', 'message' => 'La sucursal solicitada no está dentro de su alcance autorizado.'], 403);
            }
        }

        return $next($request);
    }
}
