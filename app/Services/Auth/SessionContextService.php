<?php

namespace App\Services\Auth;

use App\Http\Middleware\ResolveVpnContext;
use App\Models\User;
use App\Modules\Organization\Domain\Assignments\Exceptions\RoleScopeNotAllowed;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationAssignmentRules;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class SessionContextService
{
    public function __construct(private readonly OrganizationAssignmentRules $assignmentRules) {}

    public function for(User $user, Request $request): array
    {
        $user->load(['roleScopes' => fn ($query) => $query
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->with('branch:id,name,code')
            ->with('role.permissions')]);

        $scopes = [];
        $effectivePermissions = [];

        foreach ($user->roleScopes as $scope) {
            if (! $scope->role) {
                continue;
            }

            try {
                $organizationScope = OrganizationScope::fromString($scope->scope_type);
                $this->assignmentRules->assertRoleAllowsScope($scope->role->code, $organizationScope);
            } catch (InvalidArgumentException|RoleScopeNotAllowed) {
                continue;
            }

            if (($organizationScope === OrganizationScope::BRANCH && $scope->branch_id === null)
                || ($organizationScope === OrganizationScope::GLOBAL && $scope->branch_id !== null)
                || ($organizationScope === OrganizationScope::DISTRIBUTOR
                    && ($scope->branch_id === null || $scope->scope_id === null))) {
                continue;
            }

            $permissions = $scope->role->permissions->pluck('code')->all();
            $effectivePermissions = array_merge($effectivePermissions, $permissions);
            $scopes[] = [
                'role' => $scope->role->code,
                'role_name' => $scope->role->name,
                'branch_id' => $scope->branch_id,
                'branch_name' => $scope->branch?->name,
                'branch_code' => $scope->branch?->code,
                'scope_type' => $scope->scope_type,
                'scope_id' => $scope->scope_id,
                'permissions' => $permissions,
            ];
        }

        $vpn = (bool) $request->attributes->get(ResolveVpnContext::ATTRIBUTE, false);
        $isManager = collect($scopes)->contains(
            fn (array $scope): bool => in_array($scope['role'], ['general_manager', 'branch_manager'], true)
        );

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'state' => $user->state,
            ],
            'scopes' => $scopes,
            'effective_permissions' => array_values(array_unique($effectivePermissions)),
            'access_context' => ['vpn' => $vpn],
            'capabilities' => ['manager_actions' => $isManager && $vpn],
        ];
    }
}
