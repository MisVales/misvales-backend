<?php

namespace App\Modules\Organization\Infrastructure\IdentityAccess;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Application\Assignments\Identity\OrganizationIdentityAccess;

final class EloquentOrganizationIdentityAccess implements OrganizationIdentityAccess
{
    public function userState(string $userId): ?string
    {
        return User::query()->whereKey($userId)->value('state');
    }

    public function role(string $roleId): ?array
    {
        $role = Role::query()->find($roleId);

        return $role === null ? null : [
            'id' => $role->id,
            'code' => $role->code,
            'active' => (bool) $role->is_active,
        ];
    }

    public function activeRoles(string $userId): array
    {
        return UserRoleScope::query()
            ->select(['roles.code as role_code', 'user_role_scopes.branch_id', 'user_role_scopes.scope_type'])
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $userId)
            ->where('user_role_scopes.status', 'ACTIVE')
            ->whereNull('user_role_scopes.revoked_at')
            ->get()
            ->map(fn ($row): array => [
                'role_code' => $row->role_code,
                'branch_id' => $row->branch_id,
                'scope_type' => $row->scope_type,
            ])
            ->all();
    }
}
