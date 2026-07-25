<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class CreditDomainEvent
{
    use Dispatchable;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventId,
        public string $type,
        public array $payload,
    ) {}
}
