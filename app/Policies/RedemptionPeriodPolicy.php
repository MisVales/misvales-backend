<?php

namespace App\Policies;

use App\Models\RedemptionPeriod;
use App\Models\User;

class RedemptionPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.view_history') || $user->hasPermissionTo('catalogs.view_published');
    }

    public function view(User $user, RedemptionPeriod $redemptionPeriod): bool
    {
        return $user->hasPermissionTo('catalogs.view_history') || $user->hasPermissionTo('catalogs.view_published');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function update(User $user, RedemptionPeriod $redemptionPeriod): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function delete(User $user, RedemptionPeriod $redemptionPeriod): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }
    
    public function publish(User $user, RedemptionPeriod $redemptionPeriod): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }
}
