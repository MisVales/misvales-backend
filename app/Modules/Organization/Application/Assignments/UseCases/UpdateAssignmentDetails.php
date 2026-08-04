<?php

namespace App\Modules\Organization\Application\Assignments\UseCases;

use App\Modules\Organization\Application\Assignments\Identity\OrganizationIdentityAccess;
use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Domain\Assignments\Exceptions\AssignmentNotFound;
use App\Modules\Organization\Domain\Assignments\Exceptions\OrganizationScopeDenied;
use App\Modules\Organization\Domain\Assignments\OrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\Repositories\OrganizationAssignmentRepository;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationHierarchyResolver;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentId;
use App\Modules\Organization\Domain\Events\OrganizationEvent;
use App\Modules\Organization\Domain\Events\OrganizationEventType;
use DateTimeImmutable;
use Illuminate\Support\Str;

final readonly class UpdateAssignmentDetails
{
    public function __construct(
        private OrganizationAssignmentRepository $assignments,
        private OrganizationIdentityAccess $identity,
        private OrganizationHierarchyResolver $hierarchy,
        private OrganizationEventPublisher $events,
    ) {}

    public function handle(
        string $assignmentId,
        string $targetUserId,
        string $actorId,
        ?DateTimeImmutable $assignedAt,
        ?string $assignmentReason,
    ): OrganizationAssignment {
        $assignment = $this->assignments->find(AssignmentId::fromString($assignmentId));

        if ($assignment === null || $assignment->userId() !== $targetUserId) {
            throw new AssignmentNotFound;
        }

        if ($targetUserId === $actorId) {
            throw new OrganizationScopeDenied;
        }

        $role = $this->identity->role($assignment->roleId()) ?? throw new AssignmentNotFound;
        $this->hierarchy->assertCanManageAssignment($actorId, $role['code'], $assignment->branchId());

        $assignment->updateDetails(
            $assignedAt ?? $assignment->assignedAt(),
            $assignmentReason ?? $assignment->assignmentReason(),
        );
        $this->assignments->updateDetails($assignment);
        $this->events->publish(new OrganizationEvent(
            id: Str::uuid()->toString(),
            type: OrganizationEventType::ASSIGNMENT_UPDATED,
            aggregateType: 'organization_assignment',
            aggregateId: $assignment->id()->value(),
            actorId: $actorId,
            affectedUserId: $targetUserId,
            branchId: $assignment->branchId()?->value(),
            roleId: $assignment->roleId(),
            previousScope: $assignment->scope()->value,
            newScope: $assignment->scope()->value,
            reason: $assignment->assignmentReason(),
            notifyUserIds: [$targetUserId],
        ));

        return $assignment;
    }
}
