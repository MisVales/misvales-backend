<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequireXRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Request-Id');
        
        if (!$key || !Str::isUuid($key)) {
            return response()->json([
                'error' => 'INVALID_REQUEST_ID',
                'message' => 'Para operaciones de escritura es obligatorio enviar un X-Request-Id válido (formato UUID).'
            ], 400);
        }
        
        return $next($request);
    }
}
