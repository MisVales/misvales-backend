<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Integrations;

use App\Modules\Client\Application\Contracts\AuthorizedMobilityPort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Deniega reasignaciones mientras M15 no confirme su flujo propietario. */
final class UnavailableAuthorizedMobilityPort implements AuthorizedMobilityPort
{
    public function assertAuthorized(string $operationId, string $clientId, string $sourceDistributorId, string $destinationDistributorId): void
    {
        throw ClientDomainException::integrationUnavailable('M15_AUTHORIZED_MOBILITY');
    }
}
