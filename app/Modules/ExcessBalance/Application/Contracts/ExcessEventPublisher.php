<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Contracts;

interface ExcessEventPublisher
{
    /** @param array<string, mixed> $payload */
    public function append(string $type, string $aggregateId, array $payload, string $idempotencyKey): void;
}
