<?php

namespace App\Modules\Organization\Domain\Assignments\Repositories;

use App\Modules\Organization\Domain\Assignments\OrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentId;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;

interface OrganizationAssignmentRepository
{
    public function find(AssignmentId $id): ?OrganizationAssignment;

    /** @return list<OrganizationAssignment> */
    public function historyForUser(string $userId): array;

    /** @return list<OrganizationAssignment> */
    public function activeForUserAndRole(string $userId, string $roleId): array;

    public function hasActiveDuplicate(
        string $userId,
        string $roleId,
        ?BranchId $branchId,
        OrganizationScope $scope,
    ): bool;

    public function hasActiveAssignmentsInBranch(BranchId $branchId): bool;

    public function save(OrganizationAssignment $assignment): void;

    public function updateDetails(OrganizationAssignment $assignment): void;

    public function close(OrganizationAssignment $assignment): void;

    /** @param list<OrganizationAssignment> $assignmentsToClose */
    public function replace(array $assignmentsToClose, OrganizationAssignment $newAssignment): void;
}
