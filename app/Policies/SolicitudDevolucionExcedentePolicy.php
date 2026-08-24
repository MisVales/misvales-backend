<?php

namespace App\Policies;

use App\Models\SolicitudDevolucionExcedente;
use App\Models\User;

final class SolicitudDevolucionExcedentePolicy
{
    public function view(User $user, SolicitudDevolucionExcedente $refund): bool
    {
        return $user->hasPermissionTo('surpluses.view_global')
            || ($user->hasPermissionTo('surpluses.view_branch') && $user->hasScopeForBranch($refund->branch_id))
            || ($user->hasPermissionTo('surpluses.view_own') && $refund->surplus?->distributor_id === $user->distribuidora?->id);
    }

    public function authorize(User $user, SolicitudDevolucionExcedente $refund): bool
    {
        if ($user->id === $refund->requested_by || $user->hasRole('cashier')) {
            return false;
        }

        return ($user->hasRole('general_manager') && $user->hasPermissionTo('refunds.authorize_global'))
            || ($user->hasRole('branch_manager') && $user->hasPermissionTo('refunds.authorize_branch') && $user->hasScopeForBranch($refund->branch_id));
    }

    public function complete(User $user, SolicitudDevolucionExcedente $refund): bool
    {
        return $user->hasRole('cashier')
            && $user->hasPermissionTo('refunds.execute_branch')
            && $user->hasScopeForBranch($refund->branch_id);
    }

    public function cancel(User $user, SolicitudDevolucionExcedente $refund): bool
    {
        return $user->hasRole('distributor') && $refund->requested_by === $user->id;
    }
}
