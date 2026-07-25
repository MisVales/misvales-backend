<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\BankAccounts;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Persistence\Models\ClientBankAccount;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use Illuminate\Database\Eloquent\Collection;

/** Devuelve únicamente versiones enmascaradas a la distribuidora vigente. */
final readonly class BankAccountQueryService
{
    public function __construct(private DistributorProfilePort $profiles) {}

    /** @return Collection<int, ClientBankAccount> */
    public function forClient(string $clientId, ClientActorContext $actor): Collection
    {
        if (
            $actor->role !== RoleCode::DISTRIBUTOR
            || ! $actor->hasPermission(PermissionCode::CLIENTS_VIEW_ASSIGNED->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
        $profile = $this->profiles->forAuthenticatedDistributor($actor->userId);
        if (! ClientDistributorAssignment::query()
            ->where('client_id', $clientId)
            ->where('distributor_id', $profile->distributorId)
            ->where('active_slot', true)
            ->exists()) {
            throw ClientDomainException::notFoundOrOutOfScope();
        }

        return ClientBankAccount::query()
            ->where('client_id', $clientId)
            ->orderByDesc('effective_from')
            ->get(['id', 'client_id', 'account_last4', 'effective_from', 'effective_to', 'active_slot']);
    }
}
