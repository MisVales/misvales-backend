<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

use App\Modules\Client\Application\Security\ClientActorContext;

/** Registra auditoría protegida en la misma transacción que la escritura M06. */
interface ClientAuditPort
{
    /**
     * @param  list<string>  $changedFields
     */
    public function record(
        string $eventType,
        ?string $clientId,
        ClientActorContext $actor,
        ?string $distributorId,
        ?string $operationId,
        array $changedFields,
        string $result,
        string $requestId,
        ?string $reason = null,
        ?string $protectedPrevious = null,
        ?string $protectedNew = null,
        ?int $requestedBy = null,
        ?int $authorizedBy = null,
    ): void;
}
