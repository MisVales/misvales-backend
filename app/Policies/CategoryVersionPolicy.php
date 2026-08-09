<?php

namespace App\Policies;

use App\Models\CategoryVersion;
use App\Models\User;

class CategoryVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.view_history');
    }

    public function view(User $user, CategoryVersion $categoryVersion): bool
    {
        return $user->hasPermissionTo('catalogs.view_history');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function update(User $user, CategoryVersion $categoryVersion): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function delete(User $user, CategoryVersion $categoryVersion): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function publish(User $user, CategoryVersion $categoryVersion): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }
}
