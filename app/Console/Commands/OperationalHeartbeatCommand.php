<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class OperationalHeartbeatCommand extends Command
{
    protected $signature = 'operations:heartbeat';

    protected $description = 'Registra que el scheduler está ejecutándose';

    public function handle(): int
    {
        DB::table('operational_heartbeats')->upsert([['component' => 'scheduler', 'last_seen_at' => now(), 'metadata' => json_encode(['source' => 'laravel-scheduler'])]], ['component'], ['last_seen_at', 'metadata']);

        return self::SUCCESS;
    }
}
