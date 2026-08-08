<?php

namespace Tests\Unit\Modules\Organization\Infrastructure\IdentityAccess;

use App\Modules\Organization\Application\Assignments\Identity\OrganizationIdentityAccess;
use App\Modules\Organization\Domain\Assignments\Exceptions\OrganizationScopeDenied;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Infrastructure\IdentityAccess\EloquentOrganizationHierarchyResolver;
use PHPUnit\Framework\TestCase;

final class OrganizationHierarchyResolverTest extends TestCase
{
    public function test_global_general_manager_can_manage_any_organizational_assignment(): void
    {
        $resolver = new EloquentOrganizationHierarchyResolver($this->identity([
            ['role_code' => 'general_manager', 'branch_id' => null, 'scope_type' => 'GLOBAL'],
        ]));

        $resolver->assertCanManageAssignment('actor', 'branch_manager', $this->branchId('67'));

        self::assertTrue(true);
    }

    public function test_branch_manager_can_manage_operational_personnel_only_in_own_branch(): void
    {
        $branch = $this->branchId('67');
        $resolver = new EloquentOrganizationHierarchyResolver($this->identity([
            ['role_code' => 'branch_manager', 'branch_id' => $branch->value(), 'scope_type' => 'BRANCH'],
        ]));

        $resolver->assertCanManageAssignment('actor', 'cashier', $branch);

        self::assertTrue(true);
    }

    public function test_hierarchy_denies_unknown_or_cross_branch_relationships_by_default(): void
    {
        $resolver = new EloquentOrganizationHierarchyResolver($this->identity([
            ['role_code' => 'branch_manager', 'branch_id' => $this->branchId('67')->value(), 'scope_type' => 'BRANCH'],
        ]));

        $this->expectException(OrganizationScopeDenied::class);

        $resolver->assertCanManageAssignment('actor', 'cashier', $this->branchId('68'));
    }

    /** @param list<array{role_code: string, branch_id: ?string, scope_type: string}> $roles */
    private function identity(array $roles): OrganizationIdentityAccess
    {
        return new class($roles) implements OrganizationIdentityAccess
        {
            /** @param list<array{role_code: string, branch_id: ?string, scope_type: string}> $roles */
            public function __construct(private readonly array $roles) {}

            public function userState(string $userId): ?string
            {
                return 'ACTIVE';
            }

            public function role(string $roleId): ?array
            {
                return null;
            }

            public function activeRoles(string $userId): array
            {
                return $this->roles;
            }
        };
    }

    private function branchId(string $suffix): BranchId
    {
        return BranchId::fromString("019fcbec-4ba4-7721-bf39-c9729fb0bd{$suffix}");
    }
}
