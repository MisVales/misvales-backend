<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Application\Contracts;

use App\Models\User;

/** Contrato M02 para asignaciones históricas de sucursal y coordinador. */
interface OrganizationMobilityPort
{
    public function assertBranchExists(int $branchId): void;

    public function assertDistributorBranch(string $distributorId, int $branchId): void;

    public function assertCoordinatorValid(int $coordinatorId, int $branchId): void;

    /** @return list<string> */
    public function lockDistributorIdsForCoordinator(int $coordinatorId): array;

    public function moveDistributorBranch(
        string $distributorId,
        int $originBranchId,
        int $destinationBranchId,
        int $destinationCoordinatorId,
        string $operationId,
        string $reason,
        User $actor,
    ): void;

    /** @param array<string, int> $destinationsByDistributor */
    public function reassignCoordinatorCoverage(
        int $outgoingCoordinatorId,
        int $branchId,
        array $destinationsByDistributor,
        string $operationId,
        string $reason,
        User $actor,
    ): void;
}
