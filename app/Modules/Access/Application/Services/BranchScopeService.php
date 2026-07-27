<?php

namespace App\Modules\Access\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Contracts\BranchScopeCheckerInterface;

class BranchScopeService implements BranchScopeCheckerInterface
{
    public function canAccessBranch(int $userId, int $branchId): bool
    {
        $user = User::with('role')->find($userId);

        if (!$user || !$user->role) {
            return false;
        }

        $roleScope = $user->role->scope ?? 'LOCAL';

        // Si el rol es de alcance global, tiene acceso a cualquier sucursal
        if ($roleScope === 'GLOBAL') {
            return true;
        }

        // Si es de alcance local, debe coincidir estrictamente con su sucursal asignada
        return $user->branch_id === $branchId;
    }
}