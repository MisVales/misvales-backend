<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.view_history') || $user->hasPermissionTo('catalogs.view_published');
    }

    public function view(User $user, Category $category): bool
    {
        return $user->hasPermissionTo('catalogs.view_history') || $user->hasPermissionTo('catalogs.view_published');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function update(User $user, Category $category): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }
}
