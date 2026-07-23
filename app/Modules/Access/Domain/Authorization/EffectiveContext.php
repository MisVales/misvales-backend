<?php

namespace App\Modules\Access\Domain\Authorization;

/**
 * Representa el rol, alcance y permisos efectivos de una cuenta en una versión concreta.
 *
 * @param  list<PermissionCode>  $permissions
 */
final readonly class EffectiveContext
{
    public function __construct(
        public int $userId,
        public RoleCode $role,
        public ?int $branchId,
        public array $permissions,
        public int $version,
    ) {}
}
