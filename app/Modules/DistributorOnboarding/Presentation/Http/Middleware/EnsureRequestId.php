<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Garantiza un identificador opaco para correlacionar respuesta, auditoría y errores. */
final class EnsureRequestId
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
