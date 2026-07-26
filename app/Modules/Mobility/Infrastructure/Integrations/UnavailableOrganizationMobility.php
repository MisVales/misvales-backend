<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Mobility\Application\Contracts\OrganizationMobilityPort;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;

/** Deniega antes de mutar mientras M02 no publique sus asignaciones históricas. */
final class UnavailableOrganizationMobility implements OrganizationMobilityPort
{
    public function assertBranchExists(int $branchId): void
    {
        throw MobilityException::dependencyUnavailable('M02_BRANCH_ASSIGNMENTS');
    }

    public function assertDistributorBranch(string $distributorId, int $branchId): void
    {
        throw MobilityException::dependencyUnavailable('M02_DISTRIBUTOR_BRANCH_ASSIGNMENTS');
    }

    public function assertCoordinatorValid(int $coordinatorId, int $branchId): void
    {
        throw MobilityException::dependencyUnavailable('M02_COORDINATOR_DISTRIBUTOR_ASSIGNMENTS');
    }

    public function lockDistributorIdsForCoordinator(int $coordinatorId): array
    {
        throw MobilityException::dependencyUnavailable('M02_COORDINATOR_DISTRIBUTOR_ASSIGNMENTS');
    }

    public function moveDistributorBranch(
        string $distributorId,
        int $originBranchId,
        int $destinationBranchId,
        int $destinationCoordinatorId,
        string $operationId,
        string $reason,
        User $actor,
    ): void {
        throw MobilityException::dependencyUnavailable('M02_DISTRIBUTOR_BRANCH_ASSIGNMENTS');
    }

    public function reassignCoordinatorCoverage(
        int $outgoingCoordinatorId,
        int $branchId,
        array $destinationsByDistributor,
        string $operationId,
        string $reason,
        User $actor,
    ): void {
        throw MobilityException::dependencyUnavailable('M02_COORDINATOR_DISTRIBUTOR_ASSIGNMENTS');
    }
}
