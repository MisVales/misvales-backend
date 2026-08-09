<?php
namespace App\Policies;
use App\Models\User;
use App\Models\DistributorApplication;

class DistributorApplicationPolicy {
    public function before(User $user) {
        if ($user->hasRole('general_manager') || $user->hasRole('admin')) return true;
    }
    public function view(User $user, DistributorApplication $app) {
        if ($user->hasRole('branch_manager') || $user->hasRole('coordinator')) return $user->branch_id === $app->branch_id;
        return false;
    }
    public function decide(User $user, DistributorApplication $app) {
        if ($user->hasRole('branch_manager')) return $user->branch_id === $app->branch_id;
        if ($user->hasRole('coordinator')) return $user->id === $app->coordinator_id;
        return false;
    }
}
