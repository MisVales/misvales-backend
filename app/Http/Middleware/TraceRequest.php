<?php

namespace App\Http\Middleware;

use App\Models\RegistroOperacional;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        $started = hrtime(true);
        $requestId = $this->identifier($request->header('X-Request-Id'));
        $correlationId = $this->identifier($request->header('X-Correlation-Id'));
        $traceId = $this->identifier($request->header('X-Trace-Id'));

        // Guardamos en la petición por si un log interno necesita anexarlo
        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);
        $request->attributes->set('trace_id', $traceId);
        Log::withContext(['request_id' => $requestId, 'correlation_id' => $correlationId, 'trace_id' => $traceId]);

        $response = $next($request);

        // Añadimos la cabecera a la respuesta para que el frontend lo pueda reportar en caso de error
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $correlationId);
        $response->headers->set('X-Trace-Id', $traceId);
        $this->record($request, $response, (int) ((hrtime(true) - $started) / 1_000_000));

        return $response;
    }

    private function identifier(?string $value): string
    {
        return $value && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) ? $value : (string) Str::uuid();
    }

    private function record(Request $request, Response $response, int $duration): void
    {
        try {
            if (! Schema::hasTable('operational_logs')) {
                return;
            }
            $status = $response->getStatusCode();
            RegistroOperacional::query()->create([
                'channel' => $status >= 500 ? 'ERROR' : ($request->is('api/*') ? 'OPERATION' : 'APPLICATION'),
                'level' => $status >= 500 ? 'ERROR' : ($status >= 400 ? 'WARNING' : 'INFO'),
                'event' => 'HTTP_REQUEST_COMPLETED',
                'actor_id' => $request->user()?->id,
                'branch_id' => $request->user()?->branch_id,
                'request_id' => $request->attributes->get('request_id'),
                'correlation_id' => $request->attributes->get('correlation_id'),
                'trace_id' => $request->attributes->get('trace_id'),
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status_code' => $status,
                'duration_ms' => $duration,
                'context' => ['route' => $request->route()?->getName()],
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('No fue posible persistir el log operacional.', ['exception' => $exception::class]);
        }
    }
}
