<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Profiles;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\ClientVoucherSelection;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Contracts\ResolveClientForVoucher;
use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Persistence\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/** Confirma existencia, asociación y domicilio sin consultar deuda o línea. */
final readonly class ResolveClientVoucherSelection implements ResolveClientForVoucher
{
    public function __construct(private DistributorProfilePort $profiles) {}

    public function handle(string $clientId, ClientActorContext $actor): ClientVoucherSelection
    {
        if (
            $actor->role !== RoleCode::DISTRIBUTOR
            || ! $actor->hasPermission(PermissionCode::CLIENTS_VIEW_ASSIGNED->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
        $profile = $this->profiles->forAuthenticatedDistributor($actor->userId);
        $client = Client::query()
            ->with(['currentAssignment', 'currentAddress'])
            ->whereKey($clientId)
            ->whereHas('currentAssignment', fn (Builder $assignment): Builder => $assignment
                ->where('distributor_id', $profile->distributorId))
            ->first();
        if ($client === null) {
            throw ClientDomainException::notFoundOrOutOfScope();
        }
        if ($client->currentAddress === null) {
            throw ClientDomainException::dataIncomplete('address');
        }

        return new ClientVoucherSelection(
            clientId: $client->id,
            distributorId: $profile->distributorId,
            addressAvailable: true,
            existingClient: true,
        );
    }
}
