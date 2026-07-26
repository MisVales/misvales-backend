<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Contracts;

interface ExcessOutboxTransport
{
    /** @param array<string, mixed> $payload */
    public function publish(string $eventId, string $type, array $payload): void;
}
