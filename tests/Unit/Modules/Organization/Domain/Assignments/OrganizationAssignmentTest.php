<?php

namespace Tests\Unit\Modules\Organization\Domain\Assignments;

use App\Modules\Organization\Domain\Assignments\Exceptions\AssignmentAlreadyClosed;
use App\Modules\Organization\Domain\Assignments\Exceptions\InvalidAssignmentPeriod;
use App\Modules\Organization\Domain\Assignments\Exceptions\InvalidOrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\OrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentId;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentStatus;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OrganizationAssignmentTest extends TestCase
{
    public function test_it_creates_an_active_branch_assignment(): void
    {
        $assignment = $this->branchAssignment();

        self::assertSame(AssignmentStatus::ACTIVE, $assignment->status());
        self::assertSame(OrganizationScope::BRANCH, $assignment->scope());
        self::assertSame('019fcbec-4ba4-7721-bf39-c9729fb0bd68', $assignment->branchId()?->value());
        self::assertNull($assignment->revokedAt());
    }

    public function test_branch_scope_requires_a_branch(): void
    {
        $this->expectException(InvalidOrganizationAssignment::class);
        $this->expectExceptionMessage('El alcance BRANCH requiere una sucursal.');

        OrganizationAssignment::create(
            id: $this->assignmentId(),
            userId: 'user-id',
            roleId: 'role-id',
            branchId: null,
            scope: OrganizationScope::BRANCH,
            assignedAt: new DateTimeImmutable('2026-08-04 10:00:00'),
            assignedByUserId: 'actor-id',
        );
    }

    public function test_global_scope_rejects_a_branch(): void
    {
        $this->expectException(InvalidOrganizationAssignment::class);
        $this->expectExceptionMessage('El alcance GLOBAL no admite una sucursal.');

        OrganizationAssignment::create(
            id: $this->assignmentId(),
            userId: 'user-id',
            roleId: 'role-id',
            branchId: $this->branchId(),
            scope: OrganizationScope::GLOBAL,
            assignedAt: new DateTimeImmutable('2026-08-04 10:00:00'),
            assignedByUserId: 'actor-id',
        );
    }

    public function test_it_closes_an_assignment_without_erasing_its_history(): void
    {
        $assignment = $this->branchAssignment();
        $closedAt = new DateTimeImmutable('2026-08-05 10:00:00');

        $assignment->close($closedAt, 'closing-actor-id', 'Cambio de sucursal');

        self::assertSame(AssignmentStatus::ENDED, $assignment->status());
        self::assertSame($closedAt, $assignment->revokedAt());
        self::assertSame('closing-actor-id', $assignment->revokedByUserId());
        self::assertSame('Cambio de sucursal', $assignment->revocationReason());
        self::assertSame('user-id', $assignment->userId());
        self::assertSame('role-id', $assignment->roleId());
    }

    public function test_it_updates_only_the_editable_details_while_active(): void
    {
        $assignment = $this->branchAssignment();
        $newAssignedAt = new DateTimeImmutable('2026-08-03 09:00:00');

        $assignment->updateDetails($newAssignedAt, 'Cobertura de turno vespertino');

        self::assertSame($newAssignedAt, $assignment->assignedAt());
        self::assertSame('Cobertura de turno vespertino', $assignment->assignmentReason());
        self::assertSame(AssignmentStatus::ACTIVE, $assignment->status());
    }

    public function test_closed_assignment_details_are_immutable(): void
    {
        $assignment = $this->branchAssignment();
        $assignment->close(
            new DateTimeImmutable('2026-08-05 10:00:00'),
            'closing-actor-id',
            'Cierre definitivo',
        );

        $this->expectException(AssignmentAlreadyClosed::class);

        $assignment->updateDetails(
            new DateTimeImmutable('2026-08-03 09:00:00'),
            'Intento de alteración',
        );
    }

    public function test_closing_date_must_be_after_assignment_date(): void
    {
        $assignment = $this->branchAssignment();

        $this->expectException(InvalidAssignmentPeriod::class);

        $assignment->close(
            new DateTimeImmutable('2026-08-04 10:00:00'),
            'closing-actor-id',
            'Cierre inválido',
        );
    }

    public function test_an_assignment_cannot_be_closed_twice(): void
    {
        $assignment = $this->branchAssignment();
        $assignment->close(
            new DateTimeImmutable('2026-08-05 10:00:00'),
            'closing-actor-id',
            'Primer cierre',
        );

        $this->expectException(AssignmentAlreadyClosed::class);

        $assignment->close(
            new DateTimeImmutable('2026-08-06 10:00:00'),
            'closing-actor-id',
            'Segundo cierre',
        );
    }

    public function test_a_closure_requires_a_reason(): void
    {
        $assignment = $this->branchAssignment();

        $this->expectException(InvalidOrganizationAssignment::class);
        $this->expectExceptionMessage('El motivo de finalización es obligatorio.');

        $assignment->close(
            new DateTimeImmutable('2026-08-05 10:00:00'),
            'closing-actor-id',
            '   ',
        );
    }

    private function branchAssignment(): OrganizationAssignment
    {
        return OrganizationAssignment::create(
            id: $this->assignmentId(),
            userId: 'user-id',
            roleId: 'role-id',
            branchId: $this->branchId(),
            scope: OrganizationScope::BRANCH,
            assignedAt: new DateTimeImmutable('2026-08-04 10:00:00'),
            assignedByUserId: 'actor-id',
        );
    }

    private function assignmentId(): AssignmentId
    {
        return AssignmentId::fromString('019fcbec-4ba4-7721-bf39-c9729fb0bd67');
    }

    private function branchId(): BranchId
    {
        return BranchId::fromString('019fcbec-4ba4-7721-bf39-c9729fb0bd68');
    }
}
