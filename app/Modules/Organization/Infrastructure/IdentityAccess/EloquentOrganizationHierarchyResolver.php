<?php

namespace App\Modules\Organization\Infrastructure\IdentityAccess;

use App\Modules\Organization\Application\Assignments\Identity\OrganizationIdentityAccess;
use App\Modules\Organization\Domain\Assignments\Exceptions\OrganizationScopeDenied;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationHierarchyResolver;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;

final readonly class EloquentOrganizationHierarchyResolver implements OrganizationHierarchyResolver
{
    public function __construct(private OrganizationIdentityAccess $identity) {}

    public function assertCanManageAssignment(
        string $actorId,
        string $targetRoleCode,
        ?BranchId $branchId,
    ): void {
        foreach ($this->identity->activeRoles($actorId) as $role) {
            if ($role['role_code'] === 'general_manager' && $role['scope_type'] === 'GLOBAL') {
                return;
            }

            if ($role['role_code'] === 'branch_manager'
                && $branchId !== null
                && $role['branch_id'] === $branchId->value()
                && in_array($targetRoleCode, ['coordinator', 'verifier', 'cashier'], true)) {
                return;
            }
        }

        throw new OrganizationScopeDenied;
    }
}
