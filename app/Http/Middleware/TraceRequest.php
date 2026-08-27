<?php

namespace App\Http\Middleware;

use App\Jobs\PersistOperationalHttpRequest;
use App\Support\RuntimeDiagnostics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

        Log::channel('runtime')->info('HTTP_REQUEST_STARTED', RuntimeDiagnostics::request($request));

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            Log::channel('runtime')->error('HTTP_REQUEST_FAILED', [
                ...RuntimeDiagnostics::request($request),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'exception' => RuntimeDiagnostics::exception($exception),
            ]);

            throw $exception;
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $dbDurationMs = round((float) $request->attributes->get('db_duration_ms', 0.0), 2);

        // Añadimos la cabecera a la respuesta para que el frontend lo pueda reportar en caso de error
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $correlationId);
        $response->headers->set('X-Trace-Id', $traceId);
        if (config('observability.expose_server_timing')) {
            $response->headers->set('Server-Timing', "app;dur={$durationMs}, db;dur={$dbDurationMs}");
        }

        if (config('observability.operational_http_requests')) {
            $record = [
                'channel' => $response->getStatusCode() >= 500 ? 'ERROR' : ($request->is('api/*') ? 'OPERATION' : 'APPLICATION'),
                'level' => $response->getStatusCode() >= 500 ? 'ERROR' : ($response->getStatusCode() >= 400 ? 'WARNING' : 'INFO'),
                'event' => 'HTTP_REQUEST_COMPLETED',
                'actor_id' => $request->user()?->id,
                'branch_id' => $request->user()?->branch_id,
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'trace_id' => $traceId,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'context' => [
                    'route' => $request->route()?->getName(),
                    'db_query_count' => (int) $request->attributes->get('db_query_count', 0),
                    'db_duration_ms' => $dbDurationMs,
                    'db_slow_query_count' => (int) $request->attributes->get('db_slow_query_count', 0),
                ],
                'occurred_at' => now(),
            ];

            try {
                PersistOperationalHttpRequest::dispatch($record)
                    ->onConnection(config('observability.queue_connection'));
            } catch (Throwable $exception) {
                Log::warning('No fue posible encolar el log operacional HTTP.', [
                    'exception' => $exception::class,
                    'request_id' => $requestId,
                ]);
            }
        }

        Log::channel('runtime')->log(
            $response->getStatusCode() >= 500 ? 'error' : ($response->getStatusCode() >= 400 ? 'warning' : 'info'),
            'HTTP_REQUEST_COMPLETED',
            [
                ...RuntimeDiagnostics::request($request),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'db_query_count' => (int) $request->attributes->get('db_query_count', 0),
                'db_duration_ms' => $dbDurationMs,
            ]
        );

        return $response;
    }

    private function identifier(?string $value): string
    {
        return $value && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) ? $value : (string) Str::uuid();
    }
}
