<?php

namespace App\Modules\Organization\Application\Assignments\UseCases;

use App\Modules\Organization\Application\Assignments\Identity\OrganizationIdentityAccess;
use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Domain\Assignments\Exceptions\DuplicateActiveAssignment;
use App\Modules\Organization\Domain\Assignments\Exceptions\UserNotAssignable;
use App\Modules\Organization\Domain\Assignments\OrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\Repositories\OrganizationAssignmentRepository;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationAssignmentRules;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationHierarchyResolver;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentId;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchInactive;
use App\Modules\Organization\Domain\Branches\Exceptions\BranchNotFound;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchStatus;
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Domain\Events\OrganizationEventType;
use DateTimeImmutable;
use Illuminate\Support\Str;

final readonly class AssignPersonnel
{
    public function __construct(
        private OrganizationAssignmentRepository $assignments,
        private OrganizationIdentityAccess $identity,
        private BranchRepository $branches,
        private OrganizationAssignmentRules $rules,
        private OrganizationHierarchyResolver $hierarchy,
        private OrganizationEventPublisher $events,
    ) {}

    public function handle(
        string $assignmentId,
        string $targetUserId,
        string $roleId,
        ?string $branchId,
        ?string $scope,
        DateTimeImmutable $assignedAt,
        string $actorId,
        ?string $assignmentReason = null,
    ): OrganizationAssignment {
        if ($targetUserId === $actorId || $this->identity->userState($targetUserId) !== 'ACTIVE') {
            throw new UserNotAssignable('El usuario está bloqueado, deshabilitado o no puede ampliar su propio alcance.');
        }

        $role = $this->identity->role($roleId);
        if ($role === null || ! $role['active']) {
            throw new UserNotAssignable('El rol solicitado no existe o se encuentra inactivo.');
        }

        $organizationScope = $scope === null
            ? $this->rules->allowedScopeFor($role['code'])
            : OrganizationScope::fromString($scope);
        $this->rules->assertRoleAllowsScope($role['code'], $organizationScope);
        $branch = $branchId === null ? null : BranchId::fromString($branchId);

        if ($branch !== null) {
            $organizationBranch = $this->branches->find($branch) ?? throw new BranchNotFound($branchId);
            if ($organizationBranch->status() !== BranchStatus::ACTIVE) {
                throw new BranchInactive($branchId);
            }
        }

        $this->hierarchy->assertCanManageAssignment($actorId, $role['code'], $branch);

        if ($this->assignments->hasActiveDuplicate($targetUserId, $roleId, $branch, $organizationScope)) {
            throw new DuplicateActiveAssignment;
        }

        $previous = $this->assignments->activeForUserAndRole($targetUserId, $roleId);
        $closedAt = new DateTimeImmutable;
        foreach ($previous as $assignment) {
            $minimumClosedAt = $assignment->assignedAt()->modify('+1 second');
            $effectiveClosedAt = $closedAt < $minimumClosedAt
                ? $minimumClosedAt
                : $closedAt;
            $assignment->close($effectiveClosedAt, $actorId, 'REASSIGNED');
            $closedAt = $effectiveClosedAt;
        }

        $effectiveAssignedAt = $previous === [] || $assignedAt > $closedAt ? $assignedAt : $closedAt;
        $newAssignment = OrganizationAssignment::create(
            AssignmentId::fromString($assignmentId),
            $targetUserId,
            $roleId,
            $branch,
            $organizationScope,
            $effectiveAssignedAt,
            $actorId,
            $assignmentReason,
        );

        $this->assignments->replace($previous, $newAssignment);
        $previousAssignment = $previous[0] ?? null;
        $this->events->publish(new OrganizationEvent(
            id: Str::uuid()->toString(),
            type: $previous === []
                ? OrganizationEventType::PERSONNEL_ASSIGNED
                : OrganizationEventType::PERSONNEL_REASSIGNED,
            aggregateType: 'organization_assignment',
            aggregateId: $newAssignment->id()->value(),
            actorId: $actorId,
            affectedUserId: $targetUserId,
            branchId: $branch?->value(),
            roleId: $roleId,
            previousScope: $previousAssignment?->scope()->value,
            newScope: $organizationScope->value,
            reason: $assignmentReason ?? ($previous === [] ? 'ASSIGNED' : 'REASSIGNED'),
            details: [
                'previous_branch_id' => $previousAssignment?->branchId()?->value(),
                'new_branch_id' => $branch?->value(),
            ],
            notifyUserIds: [$targetUserId],
        ));

        return $newAssignment;
    }
}
