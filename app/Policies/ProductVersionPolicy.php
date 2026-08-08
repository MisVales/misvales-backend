<?php

namespace App\Policies;

use App\Models\ProductVersion;
use App\Models\User;

class ProductVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.view_history');
    }

    public function view(User $user, ProductVersion $productVersion): bool
    {
        return $user->hasPermissionTo('catalogs.view_history');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function update(User $user, ProductVersion $productVersion): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function delete(User $user, ProductVersion $productVersion): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function publish(User $user, ProductVersion $productVersion): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }
}
