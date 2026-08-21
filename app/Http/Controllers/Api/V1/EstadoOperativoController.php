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

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checked_at' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }

    public function metrics(): Response
    {
        $ready = ! in_array(false, [
            $this->check(fn () => DB::selectOne('SELECT 1')),
            $this->check(fn () => Redis::connection('health')->ping()),
            $this->storage(),
            $this->scheduler(),
        ], true);
        $lines = ['# TYPE misvales_service_ready gauge', 'misvales_service_ready '.($ready ? '1' : '0')];

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
