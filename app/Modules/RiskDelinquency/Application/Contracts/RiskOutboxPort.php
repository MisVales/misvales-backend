<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Contracts;

interface RiskOutboxPort
{
    /** @param array<string, mixed> $payload */
    public function outbox(string $event, string $key, string $aggregateId, array $payload): void;
}
