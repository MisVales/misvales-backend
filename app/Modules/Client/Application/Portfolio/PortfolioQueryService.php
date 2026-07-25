<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Portfolio;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Lista únicamente la cartera de la distribuidora vigente y autenticada. */
final readonly class PortfolioQueryService
{
    public function __construct(private DistributorProfilePort $profiles) {}

    /** @return LengthAwarePaginator<int, ClientPortfolioEntry> */
    public function paginate(string $clientId, ClientActorContext $actor, int $perPage = 20): LengthAwarePaginator
    {
        if (
            $actor->role !== RoleCode::DISTRIBUTOR
            || ! $actor->hasPermission(PermissionCode::CLIENTS_PORTFOLIO_VIEW_OWN->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
        $profile = $this->profiles->forAuthenticatedDistributor($actor->userId);
        $assignment = ClientDistributorAssignment::query()
            ->where('client_id', $clientId)
            ->where('distributor_id', $profile->distributorId)
            ->where('active_slot', true)
            ->first();
        if ($assignment === null) {
            throw ClientDomainException::notFoundOrOutOfScope();
        }

        return ClientPortfolioEntry::query()
            ->where('client_id', $clientId)
            ->where('distributor_id', $profile->distributorId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), (int) config('client.pagination.maximum', 100)));
    }
}
