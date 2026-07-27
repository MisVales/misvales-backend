<?php

namespace App\Modules\Access\Domain\Contracts;

interface BranchScopeCheckerInterface
{
    /**
     * Determina si el usuario tiene permiso para acceder a una sucursal específica.
     */
    public function canAccessBranch(int $userId, int $branchId): bool;
}