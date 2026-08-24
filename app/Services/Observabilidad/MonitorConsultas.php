<?php

namespace App\Services\Observabilidad;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MonitorConsultas
{
    public function register(): void
    {
        if (! config('observability.query_metrics') && ! config('observability.slow_query_log')) {
            return;
        }

        DB::listen(fn (QueryExecuted $query): mixed => $this->record($query));
    }

    public function record(QueryExecuted $query): void
    {
        $durationMs = max(0.0, (float) ($query->time ?? 0.0));
        $slowThresholdMs = max(1, (int) config('observability.slow_query_threshold_ms', 500));
        $isSlow = $durationMs >= $slowThresholdMs;

        if (config('observability.query_metrics')) {
            $this->recordRequestMetrics($durationMs, $isSlow);
        }

        if (! config('observability.slow_query_log') || ! $isSlow) {
            return;
        }

        $request = $this->request();
        Log::channel((string) config('observability.slow_query_channel', 'performance'))
            ->warning('SLOW_DATABASE_QUERY', [
                'connection' => $query->connectionName,
                'read_write_type' => $query->readWriteType,
                'duration_ms' => round($durationMs, 2),
                'query_fingerprint' => substr(hash('sha256', $this->normalizeSql($query->sql)), 0, 16),
                'request_id' => $request?->attributes->get('request_id'),
                'correlation_id' => $request?->attributes->get('correlation_id'),
                'trace_id' => $request?->attributes->get('trace_id'),
            ]);
    }

    private function recordRequestMetrics(float $durationMs, bool $isSlow): void
    {
        $request = $this->request();
        if ($request === null || ! $request->attributes->has('request_id')) {
            return;
        }

        $request->attributes->set(
            'db_query_count',
            (int) $request->attributes->get('db_query_count', 0) + 1,
        );
        $request->attributes->set(
            'db_duration_ms',
            (float) $request->attributes->get('db_duration_ms', 0.0) + $durationMs,
        );

        if ($isSlow) {
            $request->attributes->set(
                'db_slow_query_count',
                (int) $request->attributes->get('db_slow_query_count', 0) + 1,
            );
        }
    }

    private function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    }
}
