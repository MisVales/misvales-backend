<?php

namespace App\Modules\Access\Domain\Contracts;

interface OrganizationContextInvalidatorInterface
{
    /**
     * Invalida la sesión de un usuario específico.
     */
    public function invalidateForUser(int $userId, string $reason): void;

    /**
     * Invalida las sesiones de todos los usuarios que tengan un rol específico.
     */
    public function invalidateForRole(int $roleId, string $reason): void;

    /**
     * Invalida las sesiones de todos los usuarios en una sucursal específica.
     */
    public function invalidateForBranch(int $branchId, string $reason): void;
}
