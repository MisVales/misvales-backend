<?php

namespace App\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Models\UserRoleScope;
use App\Modules\Organization\Domain\Assignments\OrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentId;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentStatus;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;

final class OrganizationAssignmentMapper
{
    public function toDomain(UserRoleScope $record): OrganizationAssignment
    {
        return OrganizationAssignment::reconstitute(
            id: AssignmentId::fromString($record->getAttribute('id')),
            userId: $record->getAttribute('user_id'),
            roleId: $record->getAttribute('role_id'),
            branchId: $record->getAttribute('branch_id') === null
                ? null
                : BranchId::fromString($record->getAttribute('branch_id')),
            scope: OrganizationScope::fromString($record->getAttribute('scope_type')),
            assignedAt: $record->getAttribute('assigned_at')->toDateTimeImmutable(),
            status: AssignmentStatus::fromString($record->getAttribute('status')),
            assignedByUserId: $record->getAttribute('assigned_by_user_id'),
            assignmentReason: $record->getAttribute('assignment_reason'),
            revokedAt: $record->getAttribute('revoked_at')?->toDateTimeImmutable(),
            revokedByUserId: $record->getAttribute('revoked_by_user_id'),
            revocationReason: $record->getAttribute('revocation_reason'),
        );
    }

    /** @return array<string, mixed> */
    public function toPersistence(OrganizationAssignment $assignment): array
    {
        return [
            'id' => $assignment->id()->value(),
            'user_id' => $assignment->userId(),
            'role_id' => $assignment->roleId(),
            'branch_id' => $assignment->branchId()?->value(),
            'scope_type' => $assignment->scope()->value,
            'status' => $assignment->status()->value,
            'assigned_by_user_id' => $assignment->assignedByUserId(),
            'assigned_at' => $assignment->assignedAt(),
            'assignment_reason' => $assignment->assignmentReason(),
            'revoked_by_user_id' => $assignment->revokedByUserId(),
            'revoked_at' => $assignment->revokedAt(),
            'revocation_reason' => $assignment->revocationReason(),
        ];
    }
}
