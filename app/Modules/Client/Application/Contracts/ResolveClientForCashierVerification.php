<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

use App\Modules\Client\Application\Security\ClientActorContext;

/** Consulta interna auditada para la atención de un vale en caja. */
interface ResolveClientForCashierVerification
{
    public function handle(
        string $clientId,
        string $voucherId,
        ClientActorContext $cashier,
        string $requestId,
    ): ClientCashierVerificationData;
}
