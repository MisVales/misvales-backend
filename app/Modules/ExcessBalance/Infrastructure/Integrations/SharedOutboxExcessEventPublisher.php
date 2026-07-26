<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Integrations;

use App\Modules\ExcessBalance\Application\Contracts\ExcessEventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SharedOutboxExcessEventPublisher implements ExcessEventPublisher
{
    public function append(string $type, string $aggregateId, array $payload, string $idempotencyKey): void
    {
        DB::table('outbox_events')->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'type' => $type,
            'payload' => json_encode([
                'event_id' => (string) Str::uuid(),
                'type' => $type,
                'aggregate_id' => $aggregateId,
                'schema_version' => 1,
                ...$payload,
            ], JSON_THROW_ON_ERROR),
            'idempotency_key' => 'm12:'.$idempotencyKey,
            'attempts' => 0,
            'state' => 'PENDING',
            'available_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
