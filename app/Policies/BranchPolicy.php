<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;

final class BranchPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->state === 'ACTIVE' ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.view');
    }

    public function view(User $user, BranchRecord $branch): bool
    {
        return $this->viewAny($user) && $user->hasScopeForBranch($branch->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('branches.create') && $this->isGeneralManager($user);
    }

    public function updateAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.update') && $this->isGeneralManager($user);
    }

    public function activateAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.manage_state') && $this->isGeneralManager($user);
    }

    public function deactivateAny(User $user): bool
    {
        return $this->activateAny($user);
    }

    public function managePersonnel(User $user, BranchRecord $branch): bool
    {
        return $user->hasPermissionTo('roles.assign') && $user->hasScopeForBranch($branch->id);
    }

    private function isGeneralManager(User $user): bool
    {
        return $user->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'GLOBAL')
            ->whereHas('role', fn ($query) => $query->where('code', 'general_manager'))
            ->exists();
    }
}
