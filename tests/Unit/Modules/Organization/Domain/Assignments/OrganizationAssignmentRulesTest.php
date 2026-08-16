<?php

namespace Tests\Unit\Modules\Organization\Domain\Assignments;

use App\Modules\Organization\Domain\Assignments\Exceptions\RoleScopeNotAllowed;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationAssignmentRules;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrganizationAssignmentRulesTest extends TestCase
{
    #[DataProvider('allowedRoleScopes')]
    public function test_it_accepts_the_scope_defined_for_each_role(
        string $role,
        OrganizationScope $scope,
    ): void {
        $rules = new OrganizationAssignmentRules;

        $rules->assertRoleAllowsScope($role, $scope);

        self::assertSame($scope, $rules->allowedScopeFor($role));
    }

    /** @return array<string, array{string, OrganizationScope}> */
    public static function allowedRoleScopes(): array
    {
        return [
            'general manager' => ['general_manager', OrganizationScope::GLOBAL],
            'admin' => ['admin', OrganizationScope::GLOBAL],
            'branch manager' => ['branch_manager', OrganizationScope::BRANCH],
            'coordinator' => ['coordinator', OrganizationScope::BRANCH],
            'verifier' => ['verifier', OrganizationScope::BRANCH],
            'cashier' => ['cashier', OrganizationScope::BRANCH],
            'distributor' => ['distributor', OrganizationScope::DISTRIBUTOR],
        ];
    }

    public function test_it_rejects_a_global_scope_for_a_branch_role(): void
    {
        $this->expectException(RoleScopeNotAllowed::class);

        (new OrganizationAssignmentRules)->assertRoleAllowsScope(
            'branch_manager',
            OrganizationScope::GLOBAL,
        );
    }

    public function test_it_denies_unknown_roles_by_default(): void
    {
        $this->expectException(RoleScopeNotAllowed::class);

        (new OrganizationAssignmentRules)->allowedScopeFor('unknown_role');
    }
}
