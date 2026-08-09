<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.view_history') || $user->hasPermissionTo('catalogs.view_published');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermissionTo('catalogs.view_history') || $user->hasPermissionTo('catalogs.view_published');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }
}
