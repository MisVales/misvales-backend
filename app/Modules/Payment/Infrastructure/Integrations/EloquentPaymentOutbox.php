<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\PaymentOutboxPort;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Escribe eventos M11 en el outbox compartido dentro de la transacción llamante. */
final class EloquentPaymentOutbox implements PaymentOutboxPort
{
    public function append(string $eventType, array $payload, string $idempotencyKey): void
    {
        DB::table('outbox_events')->insertOrIgnore([
            'public_id' => (string) Str::uuid(),
            'type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'idempotency_key' => $idempotencyKey,
            'attempts' => 0,
            'state' => 'PENDING',
            'available_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
