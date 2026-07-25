<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Persiste eventos sin PII completa junto con la transacción de negocio. */
interface ClientOutboxPort
{
    /** @param array<string, scalar|null> $payload */
    public function append(string $type, array $payload, string $idempotencyKey): void;
}
