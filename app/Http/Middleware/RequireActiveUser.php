<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || $request->user()->state !== 'ACTIVE') {
            return response()->json([
                'error' => 'ACCOUNT_INACTIVE',
                'message' => 'Usuario no encontrado o inactivo.'
            ], 401);
        }

        return $next($request);
    }
}
