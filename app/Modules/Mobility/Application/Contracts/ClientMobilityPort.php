<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Application\Contracts;

use App\Models\User;

/** Integración propietaria con M05/M06 para clientes y distribuidoras. */
interface ClientMobilityPort
{
    public function lockAssignment(string $clientId): ClientAssignmentSnapshot;

    public function distributor(string $publicId): DistributorSnapshot;

    /**
     * @param  list<string>  $clientIds
     * @return list<ClientAssignmentSnapshot>
     */
    public function lockAssignmentsForClients(array $clientIds): array;

    /** @return list<ClientAssignmentSnapshot> */
    public function currentAssignmentsForDistributor(string $distributorId): array;

    public function applyAssignment(
        ClientAssignmentSnapshot $current,
        DistributorSnapshot $destination,
        string $operationId,
        string $reason,
        User $actor,
    ): string;
}
