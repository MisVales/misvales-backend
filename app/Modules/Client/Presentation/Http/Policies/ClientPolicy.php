<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Client\Application\Security\ClientActorContextFactory;
use App\Modules\Client\Persistence\Models\Client;

/** Policy delgada; el query service conserva la comprobación de alcance por fila. */
final readonly class ClientPolicy
{
    public function __construct(private ClientActorContextFactory $contexts) {}

    public function viewAny(User $user): bool
    {
        $actor = $this->contexts->fromUser($user);

        return $actor->hasPermission(PermissionCode::CLIENTS_VIEW_GLOBAL->value)
            || $actor->hasPermission(PermissionCode::CLIENTS_VIEW_BRANCH->value)
            || $actor->hasPermission(PermissionCode::CLIENTS_VIEW_ASSIGNED->value);
    }

    public function create(User $user): bool
    {
        return $this->contexts->fromUser($user)->hasPermission(PermissionCode::CLIENTS_CREATE_OWN->value);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->contexts->fromUser($user)->hasPermission(PermissionCode::CLIENTS_APPLY_AUTHORIZED_CHANGE->value);
    }

    public function applyAuthorizedChange(User $user): bool
    {
        return $this->contexts->fromUser($user)->hasPermission(PermissionCode::CLIENTS_APPLY_AUTHORIZED_CHANGE->value);
    }

    public function viewPortfolio(User $user): bool
    {
        return $this->contexts->fromUser($user)->hasPermission(PermissionCode::CLIENTS_PORTFOLIO_VIEW_OWN->value);
    }

    public function writePortfolio(User $user): bool
    {
        return $this->contexts->fromUser($user)->hasPermission(PermissionCode::CLIENTS_PORTFOLIO_WRITE_OWN->value);
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }
}
