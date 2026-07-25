<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Integrations;

use App\Modules\Client\Application\Contracts\ClientOutboxPort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Agrega eventos de M06 al outbox compartido sin incluir datos personales completos. */
final class EloquentClientOutbox implements ClientOutboxPort
{
    public function append(string $type, array $payload, string $idempotencyKey): void
    {
        DB::table('outbox_events')->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'idempotency_key' => $idempotencyKey,
            'attempts' => 0,
            'state' => 'PENDING',
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
