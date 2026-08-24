<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

final class EstadoOperativoController extends Controller
{
    public function readiness(): JsonResponse
    {
        $checks = ['mariadb' => $this->check(fn () => DB::selectOne('SELECT 1')), 'redis' => $this->check(fn () => Redis::connection('health')->ping()), 'private_storage' => $this->storage(), 'scheduler' => $this->scheduler()];
        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checked_at' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }

    public function metrics(): Response
    {
        $dependencies = [
            'mariadb' => $this->check(fn () => DB::selectOne('SELECT 1')),
            'redis' => $this->check(fn () => Redis::connection('health')->ping()),
            'private_storage' => $this->storage(),
            'scheduler' => $this->scheduler(),
        ];
        $ready = ! in_array(false, $dependencies, true);
        $lines = [
            '# TYPE misvales_service_ready gauge',
            'misvales_service_ready '.($ready ? '1' : '0'),
            '# TYPE misvales_dependency_up gauge',
        ];
        foreach ($dependencies as $dependency => $up) {
            $lines[] = sprintf('misvales_dependency_up{dependency="%s"} %d', $dependency, $up ? 1 : 0);
        }

        $lines[] = '# TYPE misvales_horizon_up gauge';
        $lines[] = 'misvales_horizon_up '.($this->horizonRunning() ? '1' : '0');
        $lines[] = '# TYPE misvales_failed_jobs_total gauge';
        $lines[] = 'misvales_failed_jobs_total '.$this->tableCount('failed_jobs');
        $lines[] = '# TYPE misvales_http_5xx_recent_total gauge';
        $lines[] = 'misvales_http_5xx_recent_total '.$this->recentServerErrors();
        $lines[] = '# TYPE misvales_scheduled_task_failures_recent_total gauge';
        $lines[] = 'misvales_scheduled_task_failures_recent_total '.$this->recentCount('operational_logs', 'event', 'SCHEDULED_TASK_FAILED', 'occurred_at');
        $lines[] = '# TYPE misvales_relation_process_failures_recent_total gauge';
        $lines[] = 'misvales_relation_process_failures_recent_total '.$this->recentCount('relation_process_runs', 'status', 'FAILED', 'updated_at');
        $lines[] = '# TYPE misvales_bank_file_rejections_recent_total gauge';
        $lines[] = 'misvales_bank_file_rejections_recent_total '.$this->recentCount('bank_file_imports', 'status', 'REJECTED', 'updated_at');
        $lines[] = '# TYPE misvales_bank_file_missing_recent_total gauge';
        $lines[] = 'misvales_bank_file_missing_recent_total '.$this->recentCount('audit_logs', 'event_name', 'LateFeeDeferredMissingBankFile', 'created_at');
        $lines[] = '# TYPE misvales_bank_reconciliation_pending gauge';
        foreach (['UNRECONCILED', 'ERROR', 'MANUAL_REQUESTED', 'MANUAL_AUTHORIZED'] as $status) {
            $lines[] = sprintf('misvales_bank_reconciliation_pending{status="%s"} %d', $status, $this->tableCountWhere('bank_movements', 'reconciliation_status', $status));
        }
        $lines[] = '# TYPE misvales_queue_depth gauge';
        foreach ([
            config('broadcasting.queue', 'broadcasts'),
            config('queue.connections.redis.queue', 'default'),
        ] as $queue) {
            $lines[] = sprintf('misvales_queue_depth{queue="%s"} %d', $queue, $this->queueDepth($queue));
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }

    private function check(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function storage(): bool
    {
        $path = 'health/'.Str::uuid();
        try {
            $disk = config('filesystems.default');
            Storage::disk($disk)->put($path, 'ok');

            return Storage::disk($disk)->delete($path);
        } catch (\Throwable) {
            return false;
        }
    }

    private function scheduler(): bool
    {
        return DB::table('operational_heartbeats')
            ->where('component', 'scheduler')
            ->where('last_seen_at', '>=', now()->subMinutes(5))
            ->exists();
    }

    private function horizonRunning(): bool
    {
        try {
            return Horizon::status() === 'running';
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableCount(string $table): int
    {
        try {
            return Schema::hasTable($table) ? DB::table($table)->count() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function recentServerErrors(): int
    {
        try {
            if (! Schema::hasTable('operational_logs')) {
                return 0;
            }

            return DB::table('operational_logs')
                ->where('status_code', '>=', 500)
                ->where('occurred_at', '>=', now()->subMinutes(5))
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function queueDepth(string $queue): int
    {
        try {
            return (int) Redis::connection(config('queue.connections.redis.connection', 'queue'))
                ->llen('queues:'.$queue);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function recentCount(string $table, string $column, string $value, string $timestamp): int
    {
        try {
            return Schema::hasTable($table)
                ? DB::table($table)->where($column, $value)->where($timestamp, '>=', now()->subDay())->count()
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function tableCountWhere(string $table, string $column, string $value): int
    {
        try {
            return Schema::hasTable($table) ? DB::table($table)->where($column, $value)->count() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
