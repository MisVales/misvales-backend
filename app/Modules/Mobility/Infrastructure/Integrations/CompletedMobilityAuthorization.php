<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Integrations;

use App\Modules\Client\Application\Contracts\AuthorizedMobilityPort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Mobility\Infrastructure\Persistence\Models\AdministrativeReassignmentItem;
use App\Modules\Mobility\Infrastructure\Persistence\Models\ClientTransfer;

/** Contrato que M06 puede consultar sin reproducir decisiones de M15. */
final class CompletedMobilityAuthorization implements AuthorizedMobilityPort
{
    public function assertAuthorized(
        string $operationId,
        string $clientId,
        string $sourceDistributorId,
        string $destinationDistributorId,
    ): void {
        $transfer = ClientTransfer::query()
            ->whereKey($operationId)
            ->where('client_id', $clientId)
            ->where('origin_distributor_id', $sourceDistributorId)
            ->where('recipient_distributor_id', $destinationDistributorId)
            ->whereIn('status', ['ORIGIN_EXIT_AUTHORIZED', 'COMPLETED'])
            ->exists();
        $reassignment = AdministrativeReassignmentItem::query()
            ->whereKey($operationId)
            ->where('client_id', $clientId)
            ->where('origin_distributor_id', $sourceDistributorId)
            ->where('destination_distributor_id', $destinationDistributorId)
            ->whereIn('status', ['VALIDATED', 'COMPLETED'])
            ->exists();
        if (! $transfer && ! $reassignment) {
            throw ClientDomainException::integrationUnavailable('M15_AUTHORIZED_MOBILITY');
        }
    }
}
