<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        
        if (!$key) {
            return response()->json([
                'error' => 'MISSING_IDEMPOTENCY_KEY',
                'message' => 'El header Idempotency-Key es obligatorio para esta operación crítica.'
            ], 400);
        }
        
        $cacheKey = "idempotency:{$key}";
        
        $cachedResponse = Cache::get($cacheKey);
        if ($cachedResponse) {
            $response = unserialize($cachedResponse);
            $response->headers->set('X-Idempotent-Replayed', 'true');
            return $response;
        }

        $lock = Cache::lock("idempotency_lock:{$key}", 10);
        if (!$lock->get()) {
            return response()->json([
                'error' => 'CONCURRENT_REQUEST',
                'message' => 'Una petición con este Idempotency-Key ya está en progreso.'
            ], 409);
        }

        try {
            $response = $next($request);
            
            if ($response->isSuccessful()) {
                Cache::put($cacheKey, serialize($response), now()->addHours(24));
            }
            
            return $response;
        } finally {
            $lock->release();
        }
    }
}
