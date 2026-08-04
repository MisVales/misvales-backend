<?php

namespace Tests\Unit\Modules\Organization\Infrastructure\Persistence\Eloquent;

use App\Models\UserRoleScope;
use App\Modules\Organization\Domain\Assignments\OrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentId;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentStatus;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\OrganizationAssignmentMapper;
use DateTimeImmutable;
use Tests\TestCase;

final class OrganizationAssignmentMapperTest extends TestCase
{
    public function test_it_maps_the_canonical_module_one_fields_to_the_domain(): void
    {
        $record = new UserRoleScope;
        $record->forceFill([
            'id' => '019fcbec-4ba4-7721-bf39-c9729fb0bd67',
            'user_id' => 'user-id',
            'role_id' => 'role-id',
            'branch_id' => '019fcbec-4ba4-7721-bf39-c9729fb0bd68',
            'scope_type' => 'BRANCH',
            'status' => 'ENDED',
            'assigned_by_user_id' => 'assigning-actor',
            'assigned_at' => '2026-08-04 10:00:00+00',
            'assignment_reason' => 'Cobertura temporal',
            'revoked_by_user_id' => 'closing-actor',
            'revoked_at' => '2026-08-05 10:00:00+00',
            'revocation_reason' => 'Cambio de sucursal',
        ]);

        $assignment = (new OrganizationAssignmentMapper)->toDomain($record);

        self::assertSame(AssignmentStatus::ENDED, $assignment->status());
        self::assertSame(OrganizationScope::BRANCH, $assignment->scope());
        self::assertSame('assigning-actor', $assignment->assignedByUserId());
        self::assertSame('Cobertura temporal', $assignment->assignmentReason());
        self::assertSame('closing-actor', $assignment->revokedByUserId());
        self::assertSame('Cambio de sucursal', $assignment->revocationReason());
    }

    public function test_it_maps_an_assignment_to_the_existing_column_names(): void
    {
        $assignment = OrganizationAssignment::create(
            id: AssignmentId::fromString('019fcbec-4ba4-7721-bf39-c9729fb0bd67'),
            userId: 'user-id',
            roleId: 'role-id',
            branchId: BranchId::fromString('019fcbec-4ba4-7721-bf39-c9729fb0bd68'),
            scope: OrganizationScope::BRANCH,
            assignedAt: new DateTimeImmutable('2026-08-04 10:00:00+00'),
            assignedByUserId: 'assigning-actor',
            assignmentReason: 'Cobertura temporal',
        );

        $attributes = (new OrganizationAssignmentMapper)->toPersistence($assignment);

        self::assertArrayHasKey('assigned_at', $attributes);
        self::assertArrayHasKey('assigned_by_user_id', $attributes);
        self::assertSame('Cobertura temporal', $attributes['assignment_reason']);
        self::assertArrayHasKey('revoked_at', $attributes);
        self::assertArrayHasKey('revoked_by_user_id', $attributes);
        self::assertArrayHasKey('revocation_reason', $attributes);
        self::assertArrayNotHasKey('valid_from', $attributes);
        self::assertArrayNotHasKey('valid_to', $attributes);
    }
}
