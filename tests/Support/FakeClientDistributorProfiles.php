<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;

final class FakeClientDistributorProfiles implements DistributorProfilePort
{
    /** @var array<int, DistributorProfile> */
    private array $byUser = [];

    /** @var array<string, DistributorProfile> */
    private array $byId = [];

    /** @var array<int, list<string>> */
    private array $coordinatorIds = [];

    public function addForUser(int $userId, DistributorProfile $profile): void
    {
        $this->byUser[$userId] = $profile;
        $this->byId[$profile->distributorId] = $profile;
    }

    public function add(DistributorProfile $profile): void
    {
        $this->byId[$profile->distributorId] = $profile;
    }

    /** @param list<string> $distributorIds */
    public function setCoordinatorScope(int $userId, array $distributorIds): void
    {
        $this->coordinatorIds[$userId] = $distributorIds;
    }

    public function forAuthenticatedDistributor(int $userId): DistributorProfile
    {
        return $this->byUser[$userId]
            ?? throw ClientDomainException::integrationUnavailable('TEST_DISTRIBUTOR_PROFILE');
    }

    public function activeById(string $distributorId): DistributorProfile
    {
        return $this->byId[$distributorId]
            ?? throw ClientDomainException::integrationUnavailable('TEST_DISTRIBUTOR_PROFILE');
    }

    public function activeByIds(array $distributorIds): array
    {
        return array_filter(
            $this->byId,
            static fn (DistributorProfile $profile, string $id): bool => in_array($id, $distributorIds, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function activeDistributorIdsForBranch(int $branchId): array
    {
        return array_keys(array_filter(
            $this->byId,
            static fn (DistributorProfile $profile): bool => $profile->branchId === $branchId,
        ));
    }

    public function activeDistributorIdsForBranchPublicId(string $branchPublicId): array
    {
        return array_keys(array_filter(
            $this->byId,
            static fn (DistributorProfile $profile): bool => $profile->branchPublicId === $branchPublicId,
        ));
    }

    public function activeDistributorIdsForCoordinator(int $coordinatorUserId): array
    {
        return $this->coordinatorIds[$coordinatorUserId] ?? [];
    }
}
