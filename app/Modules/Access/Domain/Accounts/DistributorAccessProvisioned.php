<?php

namespace App\Modules\Access\Domain\Accounts;

use Illuminate\Foundation\Events\Dispatchable;

/** Informa que una cuenta de distribuidora fue provisionada sin exponer credenciales. */
final class DistributorAccessProvisioned
{
    use Dispatchable;

    public function __construct(
        public readonly string $userPublicId,
        public readonly string $distributorId,
        public readonly string $eventKey,
    ) {}
}
