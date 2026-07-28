<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Distributor\Persistence\Models\Distributor;

/**
 * Integra la proyección pública solicitada por M06 con los perfiles reales de
 * M05 y las sucursales internas de M01.
 */
final class EloquentDistributorProfiles implements DistributorProfilePort
{
    public function forAuthenticatedDistributor(int $userId): DistributorProfile
    {
        $publicId = User::query()->whereKey($userId)->value('public_id');
        if (! is_string($publicId)) {
            throw ClientDomainException::integrationUnavailable('M05_DISTRIBUTOR_PROFILE');
        }

        $distributorId = Distributor::query()
            ->where('user_id', $publicId)
            ->where('status', 'ACTIVE')
            ->value('id');

        return is_string($distributorId)
            ? $this->activeById($distributorId)
            : throw ClientDomainException::integrationUnavailable('M05_DISTRIBUTOR_PROFILE');
    }

    public function activeById(string $distributorId): DistributorProfile
    {
        $distributor = Distributor::query()
            ->whereKey($distributorId)
            ->where('status', 'ACTIVE')
            ->first();
        if ($distributor === null) {
            throw ClientDomainException::integrationUnavailable('M05_DISTRIBUTOR_PROFILE');
        }
        $branch = Branch::query()
            ->where('public_id', $distributor->branch_id)
            ->where('is_active', true)
            ->first();
        if ($branch === null) {
            throw ClientDomainException::integrationUnavailable('M02_ACTIVE_BRANCH');
        }

        return new DistributorProfile(
            distributorId: $distributor->id,
            number: $distributor->distributor_number,
            branchId: (int) $branch->id,
            branchPublicId: $branch->public_id,
            branchName: $branch->name,
        );
    }

    public function activeByIds(array $distributorIds): array
    {
        $profiles = [];
        foreach (array_values(array_unique($distributorIds)) as $id) {
            try {
                $profiles[$id] = $this->activeById($id);
            } catch (ClientDomainException) {
                // Los consumidores reciben exclusivamente perfiles activos.
            }
        }

        return $profiles;
    }

    public function activeDistributorIdsForBranch(int $branchId): array
    {
        $publicId = Branch::query()->whereKey($branchId)->value('public_id');

        return is_string($publicId) ? $this->activeDistributorIdsForBranchPublicId($publicId) : [];
    }

    public function activeDistributorIdsForBranchPublicId(string $branchPublicId): array
    {
        return Distributor::query()
            ->where('branch_id', $branchPublicId)
            ->where('status', 'ACTIVE')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    public function activeDistributorIdsForCoordinator(int $coordinatorUserId): array
    {
        $ids = DistributorAccessLink::query()
            ->where('coordinator_user_id', $coordinatorUserId)
            ->pluck('external_distributor_id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();

        return array_keys($this->activeByIds($ids));
    }
}
