<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

use DateTimeImmutable;

/** Puerto de M17 para publicar un sobre mínimo e idempotente después del commit. */
interface NotificationOutboxPort
{
    /** @param array<string, mixed> $payload */
    public function publish(
        string $eventType,
        array $payload,
        string $idempotencyKey,
        DateTimeImmutable $availableAt,
    ): void;
}
