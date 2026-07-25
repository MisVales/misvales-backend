<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Integrations;

use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Mantiene cerrada la dependencia hasta que M05 publique su contrato real. */
final class UnavailableDistributorProfilePort implements DistributorProfilePort
{
    public function forAuthenticatedDistributor(int $userId): DistributorProfile
    {
        throw ClientDomainException::integrationUnavailable('M05_DISTRIBUTOR_PROFILE');
    }

    public function activeById(string $distributorId): DistributorProfile
    {
        throw ClientDomainException::integrationUnavailable('M05_DISTRIBUTOR_PROFILE');
    }

    public function activeByIds(array $distributorIds): array
    {
        throw ClientDomainException::integrationUnavailable('M05_DISTRIBUTOR_PROFILE');
    }

    public function activeDistributorIdsForBranch(int $branchId): array
    {
        throw ClientDomainException::integrationUnavailable('M02_M05_BRANCH_SCOPE');
    }

    public function activeDistributorIdsForBranchPublicId(string $branchPublicId): array
    {
        throw ClientDomainException::integrationUnavailable('M02_M05_BRANCH_SCOPE');
    }

    public function activeDistributorIdsForCoordinator(int $coordinatorUserId): array
    {
        throw ClientDomainException::integrationUnavailable('M02_M05_COORDINATOR_SCOPE');
    }
}
