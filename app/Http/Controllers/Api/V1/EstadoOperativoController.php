<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EstadoOperativoController extends Controller
{
    public function readiness(): JsonResponse
    {
        $checks = ['postgresql' => $this->check(fn () => DB::selectOne('SELECT 1')), 'redis' => $this->check(fn () => Redis::connection('health')->ping()), 'private_storage' => $this->storage(), 'scheduler' => $this->scheduler()];
        $ready = ! in_array(false, $checks, true);

        return response()->json(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks, 'failed_jobs' => DB::table('failed_jobs')->count(), 'queued_jobs' => config('queue.default') === 'database' ? DB::table('jobs')->count() : null, 'checked_at' => now()->toIso8601String()], $ready ? 200 : 503);
    }

    public function metrics(): Response
    {
        $lines = [
            '# TYPE misvales_failed_jobs gauge', 'misvales_failed_jobs '.DB::table('failed_jobs')->count(),
            '# TYPE misvales_outbox_pending gauge', 'misvales_outbox_pending '.DB::table('outbox_events')->where('status', 'PENDING')->count(),
            '# TYPE misvales_unprocessed_notifications gauge', 'misvales_unprocessed_notifications '.DB::table('notification_deliveries')->where('status', '<>', 'SENT')->count(),
            '# TYPE misvales_http_errors_total gauge', 'misvales_http_errors_total '.DB::table('operational_logs')->where('status_code', '>=', 500)->count(),
        ];

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
            ->where('last_seen_at', '>=', DB::raw("CURRENT_TIMESTAMP - INTERVAL '5 minutes'"))
            ->exists();
    }
}
