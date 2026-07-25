<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

use App\Modules\Client\Application\Security\ClientActorContext;

/** Referencia inmutable de un vale confirmado, sin duplicar su snapshot financiero. */
final readonly class RecordClientVoucherReferenceCommand
{
    public function __construct(
        public string $clientId,
        public string $distributorId,
        public string $voucherId,
        public string $amount,
        public string $occurredOn,
        public string $operationId,
        public string $requestId,
        public ClientActorContext $actor,
    ) {}
}
