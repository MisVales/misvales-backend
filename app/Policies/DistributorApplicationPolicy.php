<?php

namespace App\Policies;

use App\Models\DistributorApplication;
use App\Models\User;

class DistributorApplicationPolicy
{
    public function before(User $user)
    {
        // Administradores y gerentes generales no deben tener blanket de escritura
    }

    public function view(User $user, DistributorApplication $app)
    {
        if ($user->hasRole('admin') || $user->hasRole('general_manager')) {
            return true;
        }
        if ($user->hasRole('branch_manager') || $user->hasRole('coordinator')) {
            return $user->branch_id === $app->branch_id;
        }

        return false;
    }

    public function decide(User $user, DistributorApplication $app)
    {
        if ($user->hasRole('branch_manager')) {
            return $user->branch_id === $app->branch_id;
        }
        if ($user->hasRole('coordinator')) {
            return $user->id === $app->coordinator_id;
        }

        return false;
    }
}
