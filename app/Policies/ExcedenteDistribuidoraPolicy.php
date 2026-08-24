<?php

namespace App\Policies;

use App\Models\ExcedenteDistribuidora;
use App\Models\User;

final class ExcedenteDistribuidoraPolicy
{
    public function view(User $user, ExcedenteDistribuidora $surplus): bool
    {
        return $user->hasPermissionTo('surpluses.view_global')
            || ($user->hasPermissionTo('surpluses.view_branch') && $user->hasScopeForBranch($surplus->branch_id))
            || ($user->hasPermissionTo('surpluses.view_own') && $user->distribuidora?->id === $surplus->distributor_id);
    }

    public function chooseCredit(User $user, ExcedenteDistribuidora $surplus): bool
    {
        return $user->hasPermissionTo('surpluses.view_own') && $user->distribuidora?->id === $surplus->distributor_id;
    }

    public function requestRefund(User $user, ExcedenteDistribuidora $surplus): bool
    {
        return $this->chooseCredit($user, $surplus);
    }
}
