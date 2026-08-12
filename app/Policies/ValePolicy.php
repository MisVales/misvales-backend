<?php

namespace App\Policies;

use App\Models\CoordinatorDistributorAssignment;
use App\Models\User;
use App\Models\Vale;

final class ValePolicy
{
    public function before(User $user): ?bool
    {
        return $user->state === 'ACTIVE' ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return collect(['vouchers.view_own', 'vouchers.view_assigned', 'vouchers.view_branch', 'vouchers.view_global'])->contains(fn (string $permission): bool => $user->hasPermissionTo($permission));
    }

    public function view(User $user, Vale $vale): bool
    {
        if ($user->hasPermissionTo('vouchers.view_global')) {
            return true;
        }
        if ($user->hasPermissionTo('vouchers.view_branch') && $user->hasScopeForBranch($vale->branch_id)) {
            return true;
        }
        if ($user->hasPermissionTo('vouchers.view_own') && $user->distribuidora?->id === $vale->distributor_id) {
            return true;
        }

        return $user->hasPermissionTo('vouchers.view_assigned') && CoordinatorDistributorAssignment::query()
            ->where('coordinator_id', $user->id)->where('distributor_id', $vale->distributor_id)
            ->where('status', 'ACTIVE')->whereNull('valid_to')->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('vouchers.create_own') && $user->distribuidora !== null;
    }
}
