<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Services\Auth\SessionPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackSessionActivity
{
    protected $policyService;

    public function __construct(SessionPolicyService $policyService)
    {
        $this->policyService = $policyService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user ? $user->currentAccessToken() : null;
        $session = null;

        if ($user && $token) {
            $tokenHash = hash('sha256', $request->bearerToken());

            $session = AuthSession::where('session_identifier_hash', $tokenHash)->first();

            if ($session) {
                $policy = $this->policyService->getPolicyForUser($user);
                $now = now();

                // 1. Verificar Expiración Absoluta
                if ($session->expires_at && $session->expires_at->isPast()) {
                    $this->revokeSession($session, $token, 'ABSOLUTE_TIMEOUT');

                    return response()->json(['error' => 'INVALID_SESSION', 'message' => 'La sesión ha expirado.'], 401);
                }

                // 2. Verificar Inactividad (Punto 26)
                if ($session->last_activity_at) {
                    $minutesInactive = $session->last_activity_at->diffInMinutes($now);

                    if ($minutesInactive > $policy['inactivity']) {
                        $this->revokeSession($session, $token, 'INACTIVITY_TIMEOUT');

                        return response()->json(['error' => 'INVALID_SESSION', 'message' => 'Sesión cerrada por inactividad.'], 401);
                    }
                }

                // 3. Actualizar Actividad Controlada (Throttling)
                // Solo guardamos en base de datos si han pasado más de 2 minutos para no matar el I/O
                if (! $session->last_activity_at || $session->last_activity_at->diffInMinutes($now) >= 2) {
                    $session->last_activity_at = $now;
                    $session->save();
                }
            }
        }

        // Adjuntamos la sesión a la petición para que otros middlewares (ej. RequireMfaCompleted) la usen sin ir a BD.
        $request->attributes->set('auth_session', $session);

        return $next($request);
    }

    private function revokeSession(AuthSession $session, $accessToken, string $reason): void
    {
        $session->update([
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);

        $accessToken->delete();
    }
}
