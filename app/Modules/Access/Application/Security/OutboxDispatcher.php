<?php

namespace App\Modules\Access\Application\Security;

use App\Modules\Access\Infrastructure\Persistence\Models\OutboxEvent;
use App\Modules\Access\Infrastructure\Queue\ProcessOutboxEvent;
use Illuminate\Support\Str;

final readonly class OutboxDispatcher
{
    public function __construct(private SecretSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $type,
        string $deduplicationKey,
        array $payload,
        ?string $recipient = null,
        ?string $template = null,
    ): OutboxEvent {
        $eventUuid = (string) Str::uuid();
        $event = OutboxEvent::query()->firstOrCreate(
            ['idempotency_key' => $deduplicationKey],
            [
                'public_id' => $eventUuid,
                'event_uuid' => $eventUuid,
                'type' => $type,
                'recipient' => $recipient,
                'template' => $template,
                'payload' => $this->sanitizer->sanitize($payload),
                'state' => 'PENDING',
                'attempts' => 0,
                'available_at' => now(),
                'occurred_at' => now('UTC'),
                'next_attempt_at' => now(),
            ],
        );

        if ($event->wasRecentlyCreated && $recipient !== null && $template !== null) {
            ProcessOutboxEvent::dispatch($event->id)->afterCommit();
        }

        return $event;
    }
}
