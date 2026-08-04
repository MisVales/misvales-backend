<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMfaCompleted
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // En TrackSessionActivity se adjunta la sesión al request attributes
        $session = $request->attributes->get('auth_session');

        if (! $session || ! $session->mfa_verified_at) {
            return response()->json([
                'error' => 'INVALID_MFA',
                'message' => 'Requiere autenticación multifactor.',
            ], 403);
        }

        return $next($request);
    }
}
