<?php

namespace App\Policies;

use App\Models\ConfigurationDefinition;
use App\Models\User;

class ConfigurationDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.view_history') || $user->hasPermissionTo('catalogs.view_published');
    }

    public function view(User $user, ConfigurationDefinition $configurationDefinition): bool
    {
        return $user->hasPermissionTo('catalogs.view_history') || $user->hasPermissionTo('catalogs.view_published');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function update(User $user, ConfigurationDefinition $configurationDefinition): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }

    public function delete(User $user, ConfigurationDefinition $configurationDefinition): bool
    {
        return $user->hasPermissionTo('catalogs.manage');
    }
}
