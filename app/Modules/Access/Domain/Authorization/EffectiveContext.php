<?php

namespace App\Modules\Access\Domain\Authorization;

/** Representa el rol, alcance y permisos efectivos de una cuenta en una versión concreta. */
final readonly class EffectiveContext
{
    /** @param list<PermissionCode> $permissions */
    public function __construct(
        public int $userId,
        public RoleCode $role,
        public ?int $branchId,
        public array $permissions,
        public int $version,
    ) {}
}
