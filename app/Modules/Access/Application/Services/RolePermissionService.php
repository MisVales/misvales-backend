<?php

namespace App\Modules\Access\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Contracts\RolePermissionCheckerInterface;

class RolePermissionService implements RolePermissionCheckerInterface
{
    public function hasPermission(int $userId, string $permissionCode): bool
    {
        $user = User::with('role.permissions')->find($userId);

        if (!$user || !$user->role || !$user->role->permissions) {
            return false;
        }

        return $user->role->permissions->contains(function ($permission) use ($permissionCode) {
            $code = $permission->code instanceof \BackedEnum ? $permission->code->value : (string) $permission->code;
            return $code === $permissionCode;
        });
    }
}