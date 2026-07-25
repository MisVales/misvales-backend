<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Modules\Access\Infrastructure\Persistence\Models\OutboxEvent;
use App\Modules\DistributorOnboarding\Domain\Contracts\NotificationOutboxPort;
use DateTimeImmutable;

/** Adapta eventos confirmados de M04 al outbox persistente consumible por M17. */
final class EloquentNotificationOutbox implements NotificationOutboxPort
{
    public function publish(
        string $eventType,
        array $payload,
        string $idempotencyKey,
        DateTimeImmutable $availableAt,
    ): void {
        OutboxEvent::query()->create([
            'type' => $eventType,
            'payload' => $payload,
            'idempotency_key' => $idempotencyKey,
            'available_at' => $availableAt,
        ]);
    }
}
