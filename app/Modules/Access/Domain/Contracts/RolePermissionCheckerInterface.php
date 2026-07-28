<?php

namespace App\Modules\Access\Domain\Contracts;

interface RolePermissionCheckerInterface
{
    /**
     * Comprueba si el usuario cuenta con el permiso solicitado.
     */
    public function hasPermission(int $userId, string $permissionCode): bool;
}
