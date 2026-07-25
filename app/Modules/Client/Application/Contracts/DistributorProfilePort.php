<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Frontera de M06 hacia perfiles, sucursal y relaciones vigentes de M05/M02. */
interface DistributorProfilePort
{
    public function forAuthenticatedDistributor(int $userId): DistributorProfile;

    public function activeById(string $distributorId): DistributorProfile;

    /**
     * @param  list<string>  $distributorIds
     * @return array<string, DistributorProfile>
     */
    public function activeByIds(array $distributorIds): array;

    /** @return list<string> */
    public function activeDistributorIdsForBranch(int $branchId): array;

    /** @return list<string> */
    public function activeDistributorIdsForBranchPublicId(string $branchPublicId): array;

    /** @return list<string> */
    public function activeDistributorIdsForCoordinator(int $coordinatorUserId): array;
}
