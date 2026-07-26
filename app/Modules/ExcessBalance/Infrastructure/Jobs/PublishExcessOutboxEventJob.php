<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Jobs;

use App\Modules\ExcessBalance\Application\Contracts\ExcessOutboxTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class PublishExcessOutboxEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly string $eventPublicId)
    {
        $this->afterCommit();
    }

    public function handle(ExcessOutboxTransport $transport): void
    {
        DB::transaction(function () use ($transport): void {
            $event = DB::table('outbox_events')
                ->where('public_id', $this->eventPublicId)
                ->where('idempotency_key', 'like', 'm12:%')
                ->lockForUpdate()
                ->first();
            if ($event === null || $event->processed_at !== null) {
                return;
            }
            $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
            $transport->publish($event->public_id, $event->type, is_array($payload) ? $payload : []);
            DB::table('outbox_events')->where('id', $event->id)->update([
                'state' => 'PROCESSED',
                'processed_at' => now('UTC'),
                'attempts' => $event->attempts + 1,
                'updated_at' => now('UTC'),
            ]);
        }, 3);
    }
}
