<?php

namespace App\Modules\Organization\Infrastructure\IdentityAccess;

use App\Models\UserRoleScope;
use App\Modules\Organization\Domain\Assignments\EffectiveOrganizationScope;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationScopeResolver;

final class EloquentOrganizationScopeResolver implements OrganizationScopeResolver
{
    public function resolve(string $userId): EffectiveOrganizationScope
    {
        $assignments = UserRoleScope::query()
            ->select(['user_role_scopes.branch_id', 'user_role_scopes.scope_type', 'roles.code as role_code'])
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $userId)
            ->whereNull('user_role_scopes.revoked_at')
            ->where('user_role_scopes.status', 'ACTIVE')
            ->get();

        $hasGlobalScope = $assignments->contains(
            fn ($assignment): bool => $assignment->scope_type === 'GLOBAL'
                && in_array($assignment->role_code, ['general_manager', 'admin'], true),
        );

        if ($hasGlobalScope) {
            return EffectiveOrganizationScope::global();
        }

        $branchIds = $assignments
            ->where('scope_type', 'BRANCH')
            ->pluck('branch_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return EffectiveOrganizationScope::limitedTo($branchIds);
    }
}
