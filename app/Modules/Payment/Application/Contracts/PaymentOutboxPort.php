<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

/** Publica eventos transaccionales para integraciones posteriores al commit. */
interface PaymentOutboxPort
{
    /** @param array<string, mixed> $payload */
    public function append(string $eventType, array $payload, string $idempotencyKey): void;
}
