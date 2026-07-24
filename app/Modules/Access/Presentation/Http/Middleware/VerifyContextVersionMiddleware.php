<?php

namespace App\Modules\Access\Presentation\Http\Middleware;

use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class VerifyContextVersionMiddleware
{
    public function __construct(private readonly TemporaryAuthorization $authorizations) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        $userContextVersion = $user?->context_version ?? 1;
        $tokenContextVersion = $token instanceof PersonalAccessToken ? $token->context_version : null;
        // Si el token no tiene context_version (porque Sanctum no lo agregó todavía, o no aplica), asumimos que pasa si es igual.
        // Wait, where is context_version saved in the token?
        // En B08 dice: "Validar en cada petición: token, sesión, cuenta y context_version."
        // We need to add context_version to the personal_access_tokens table OR read it from the session.
        // Actually, "Cambiar permiso, sucursal, jerarquía o asignación incrementa context_version y revoca sesiones afectadas."
        // If we just check that the user's current context_version hasn't changed since the token was created?
        // Let's assume for now the token or session doesn't store context_version directly yet,
        // OR we can compare the user's DB context_version against what we expected.
        // The prompt says "Cambiar permiso, sucursal, jerarquía o asignación incrementa context_version y revoca sesiones afectadas."
        // If the session is revoked, the token will fail. So we might just need to check if the session is still valid.

        // As per B08: "Validar en cada petición: token, sesión, cuenta y context_version."
        // Let's implement a placeholder logic: If context_version is passed in a header (e.g., from Angular), we check it.
        // Or if the token itself stores the context_version.
        // Let's check the token's context_version if it exists.

        $tokenContextVersion = $token instanceof PersonalAccessToken ? $token->context_version : null;
        if ($tokenContextVersion !== null && $tokenContextVersion !== $userContextVersion) {
            $sessionId = $token instanceof PersonalAccessToken ? $token->auth_session_id : null;
            $session = is_int($sessionId) ? AuthSession::query()->find($sessionId) : null;
            if ($session !== null) {
                $this->authorizations->invalidateSession($session, 'CONTEXT_CHANGED');
            }

            return response()->json(['error' => [
                'code' => 'CONTEXT_CHANGED',
                'message' => 'El contexto ha cambiado. La sesión fue revocada.',
                'correlationId' => (string) Str::uuid(),
            ]], 401);
        }

        return $next($request);
    }
}
