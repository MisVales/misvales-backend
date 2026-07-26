<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

use App\Modules\Payment\Application\Security\PaymentActorContext;

/**
 * Autoriza y consume una acción crítica ligada a actor, operación, recurso, importe y sucursal.
 */
interface PaymentAuthorizationPort
{
    public function assertAndConsume(
        string $authorizationId,
        string $operation,
        PaymentActorContext $actor,
        string $resourceId,
        string $amount,
        int $branchId,
    ): void;
}
