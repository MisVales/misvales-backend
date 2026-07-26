<?php

namespace App\Console\Commands;

use App\Modules\Access\Infrastructure\Persistence\Models\OutboxEvent;
use App\Modules\Access\Infrastructure\Queue\ProcessOutboxEvent;
use Illuminate\Console\Command;

final class DispatchAccessOutbox extends Command
{
    protected $signature = 'access:outbox-dispatch {--limit=100 : Maximum events to dispatch}';

    protected $description = 'Dispatch due MisVales access outbox events to the idempotent notification worker';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $eventIds = OutboxEvent::query()
            ->whereIn('state', ['PENDING', 'RETRY'])
            ->whereNotNull('recipient')
            ->whereNotNull('template')
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->oldest('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($eventIds as $eventId) {
            ProcessOutboxEvent::dispatch((int) $eventId);
        }

        $this->info("Dispatched {$eventIds->count()} access outbox event(s).");

        return self::SUCCESS;
    }
}
