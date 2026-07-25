<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Establece el identificador opaco usado por respuesta, auditoría y errores M06. */
final class EnsureClientRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->header('X-Request-Id');
        $requestId = is_string($candidate) && Str::isUuid($candidate)
            ? $candidate
            : (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
