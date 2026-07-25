<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Confirma que M15 completó las decisiones que no pertenecen a M06. */
interface AuthorizedMobilityPort
{
    public function assertAuthorized(
        string $operationId,
        string $clientId,
        string $sourceDistributorId,
        string $destinationDistributorId,
    ): void;
}
