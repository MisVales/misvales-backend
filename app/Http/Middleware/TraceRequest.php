<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TraceRequest
{
    /**
     * Intercepta la petición para inyectar o generar un X-Request-Id.
     * Permite trazabilidad forense entre Frontend y Backend.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id', (string) Str::uuid());

        // Guardamos en la petición por si un log interno necesita anexarlo
        $request->attributes->set('request_id', $requestId);

        $response = $next($request);

        // Añadimos la cabecera a la respuesta para que el frontend lo pueda reportar en caso de error
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
