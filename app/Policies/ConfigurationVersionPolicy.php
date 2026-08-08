<?php

namespace App\Policies;

use App\Models\ConfigurationVersion;
use App\Models\User;

class ConfigurationVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.view_history');
    }

    public function view(User $user, ConfigurationVersion $configurationVersion): bool
    {
        return $user->hasPermissionTo('catalogs.view_history');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function update(User $user, ConfigurationVersion $configurationVersion): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function delete(User $user, ConfigurationVersion $configurationVersion): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function publish(User $user, ConfigurationVersion $configurationVersion): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }
}
