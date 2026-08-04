<?php

namespace App\Services;

use App\Models\OutboxEvent;

class OutboxService
{
    public static function publish(string $eventType, array $payload): void
    {
        OutboxEvent::create([
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'PENDING',
        ]);
    }
}
