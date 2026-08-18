<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Services\Auth\SessionPolicyService;
use App\Services\Auth\SessionTokenIdentifier;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class TrackSessionActivity
{
    protected $policyService;

    public function __construct(
        SessionPolicyService $policyService,
        private readonly SessionTokenIdentifier $tokenIdentifier,
    ) {
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

        if ($user && $token instanceof PersonalAccessToken) {
            $tokenHash = $this->tokenIdentifier->current($request);
            $legacyTokenHash = $this->tokenIdentifier->legacy($request);
            $identifiers = array_values(array_unique(array_filter([$tokenHash, $legacyTokenHash])));

            $session = AuthSession::query()
                ->whereIn('session_identifier_hash', $identifiers)
                ->first();

            if (! $session || $session->user_id !== $user->id) {
                $token->delete();

                return response()->json(['error' => 'INVALID_SESSION', 'message' => 'No autenticado o sesión inválida.'], 401);
            }

            if ($session->revoked_at !== null) {
                $token->delete();

                return response()->json(['error' => 'INVALID_SESSION', 'message' => 'La sesión fue revocada.'], 401);
            }

            if ($tokenHash !== null && $session->getRawOriginal('session_identifier_hash') !== $tokenHash) {
                $session->session_identifier_hash = $tokenHash;
                $session->save();
            }

            if (app()->environment('local') && $session->authentication_method === 'LOCAL_SUPER_SESSION') {
                $request->attributes->set('auth_session', $session);

                return $next($request);
            }

            $policy = $this->policyService->getPolicyForUser($user);
            $now = now();

            // 1. Verificar Expiración Absoluta
            if ($session->expires_at && $session->expires_at->isPast()) {
                $this->revokeSession($session, $token, 'ABSOLUTE_TIMEOUT');

                return response()->json(['error' => 'INVALID_SESSION', 'message' => 'La sesión ha expirado.'], 401);
            }

            // 2. Verificar inactividad según la política de sesión.
            if (! app()->environment('local') && $session->last_activity_at) {
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

        // Adjuntamos la sesión a la petición para que otros middlewares (ej. RequireMfaCompleted) la usen sin ir a BD.
        $request->attributes->set('auth_session', $session);

        return $next($request);
    }

    private function revokeSession(AuthSession $session, PersonalAccessToken $accessToken, string $reason): void
    {
        $session->update([
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);

        $accessToken->delete();
    }
}
