<?php

namespace App\Modules\Organization\Domain\Assignments\Services;

use App\Modules\Organization\Domain\Assignments\Exceptions\RoleScopeNotAllowed;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;

final class OrganizationAssignmentRules
{
    /** @var array<string, OrganizationScope> */
    private const ALLOWED_SCOPES = [
        'general_manager' => OrganizationScope::GLOBAL,
        'admin' => OrganizationScope::GLOBAL,
        'branch_manager' => OrganizationScope::BRANCH,
        'coordinator' => OrganizationScope::BRANCH,
        'verifier' => OrganizationScope::BRANCH,
        'cashier' => OrganizationScope::BRANCH,
        'distributor' => OrganizationScope::ASSIGNED,
    ];

    public function assertRoleAllowsScope(string $roleCode, OrganizationScope $scope): void
    {
        $roleCode = mb_strtolower(trim($roleCode));
        $allowedScope = self::ALLOWED_SCOPES[$roleCode] ?? null;

        if ($allowedScope !== $scope) {
            throw new RoleScopeNotAllowed($roleCode, $scope->value);
        }
    }

    public function allowedScopeFor(string $roleCode): OrganizationScope
    {
        $roleCode = mb_strtolower(trim($roleCode));

        return self::ALLOWED_SCOPES[$roleCode]
            ?? throw new RoleScopeNotAllowed($roleCode, 'UNSUPPORTED');
    }
}
