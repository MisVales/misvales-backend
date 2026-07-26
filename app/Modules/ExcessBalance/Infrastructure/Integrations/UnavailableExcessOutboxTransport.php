<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Integrations;

use App\Modules\ExcessBalance\Application\Contracts\ExcessOutboxTransport;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;

final class UnavailableExcessOutboxTransport implements ExcessOutboxTransport
{
    public function publish(string $eventId, string $type, array $payload): void
    {
        throw ExcessBalanceException::pendingDefinition(
            'M17 todavía no publica el transporte productivo para eventos de excedentes.',
        );
    }
}
