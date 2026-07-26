<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Concerns;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;

/**
 * Trait para aislar la lógica de permisos en M03 sin alterar el modelo User (M01).
 */
trait ChecksPermissions
{
    protected function checkPermission(User $user, PermissionCode $permission): bool
    {
        if (! $user->relationLoaded('role')) {
            $user->load('role.permissions');
        }

        if (! $user->role) {
            return false;
        }

        return $user->role->permissions->contains('code', $permission->value);
    }

    protected function checkCriticalAction(User $user, CriticalAction $action): bool
    {
        $actions = session('critical_actions', []);

        if (! isset($actions[$action->value])) {
            return false;
        }

        $ttl = config('configuration.modification_token_ttl_minutes', 5);
        $timePassed = time() - $actions[$action->value];

        return $timePassed <= ($ttl * 60);
    }
}
