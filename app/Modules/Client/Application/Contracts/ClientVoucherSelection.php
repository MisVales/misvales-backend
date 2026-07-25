<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Datos mínimos que M08 puede usar antes de aplicar sus propias reglas. */
final readonly class ClientVoucherSelection
{
    public function __construct(
        public string $clientId,
        public string $distributorId,
        public bool $addressAvailable,
        public bool $existingClient,
    ) {}
}
