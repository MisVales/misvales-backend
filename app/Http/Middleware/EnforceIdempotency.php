<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || ! Str::isUuid($key)) {
            return $this->error($request, 'MISSING_IDEMPOTENCY_KEY', 'El header Idempotency-Key debe contener un UUID válido.', 400);
        }

        $actor = $request->user()?->getAuthIdentifier() ?? $request->ip();
        $operacion = hash('sha256', implode('|', [$actor, $request->method(), $request->path()]));
        $cacheKey = "idempotency:{$operacion}:{$key}";
        $huella = hash('sha256', $request->getContent());

        $cachedResponse = Cache::get($cacheKey);
        if ($cachedResponse) {
            if (! is_array($cachedResponse) || ! hash_equals($cachedResponse['fingerprint'], $huella)) {
                return $this->error($request, 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD', 'La clave idempotente ya fue utilizada con otro contenido.', 409);
            }

            $response = unserialize($cachedResponse['response']);
            $response->headers->set('X-Idempotent-Replayed', 'true');

            return $response;
        }

        $lock = Cache::lock("idempotency_lock:{$key}", 10);
        if (! $lock->get()) {
            return $this->error($request, 'CONCURRENT_REQUEST', 'Una petición con esta clave idempotente ya está en progreso.', 409);
        }

        try {
            $response = $next($request);

            if ($response->isSuccessful()) {
                Cache::put($cacheKey, [
                    'fingerprint' => $huella,
                    'response' => serialize($response),
                ], now()->addHours(24));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function error(Request $request, string $codigo, string $mensaje, int $estado): Response
    {
        return response()->json(['error' => [
            'code' => $codigo,
            'message' => $mensaje,
            'fields' => (object) [],
            'details' => (object) [],
            'request_id' => $request->attributes->get('request_id'),
        ]], $estado);
    }
}
