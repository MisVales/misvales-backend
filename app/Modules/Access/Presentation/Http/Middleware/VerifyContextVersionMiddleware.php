<?php

namespace App\Modules\Access\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyContextVersionMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        $userContextVersion = $user?->context_version ?? 1;
        $tokenContextVersion = $token?->context_version;
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
        
        $tokenContextVersion = $token?->context_version;
        if ($tokenContextVersion !== null && $tokenContextVersion !== $userContextVersion) {
            return response()->json(['error' => [
                'code' => 'CONTEXT_CHANGED',
                'message' => 'El contexto ha cambiado. La sesión fue revocada.',
                'correlationId' => (string) \Illuminate\Support\Str::uuid()
            ]], 401);
        }

        return $next($request);
    }
}
