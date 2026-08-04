<?php

namespace App\Modules\Organization\Domain\Assignments\Services;

use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;

interface OrganizationHierarchyResolver
{
    public function assertCanManageAssignment(
        string $actorId,
        string $targetRoleCode,
        ?BranchId $branchId,
    ): void;
}
