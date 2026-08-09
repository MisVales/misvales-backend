<?php

namespace Tests\Feature\Organization;

use App\Models\Role;
use App\Models\User;
use App\Modules\Organization\Domain\Assignments\OrganizationAssignment;
use App\Modules\Organization\Domain\Assignments\Repositories\OrganizationAssignmentRepository;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentId;
use App\Modules\Organization\Domain\Assignments\ValueObjects\AssignmentStatus;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;
use App\Modules\Organization\Domain\Branches\ValueObjects\BranchId;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrganizationAssignmentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_closes_an_assignment_without_losing_history(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = $this->user();
        $target = $this->user();
        $branch = $this->branch($actor);
        $role = Role::query()->where('code', 'coordinator')->firstOrFail();
        $assignedAt = now()->subMinute()->toDateTimeImmutable();
        $assignment = OrganizationAssignment::create(
            id: AssignmentId::fromString(Str::uuid()->toString()),
            userId: $target->id,
            roleId: $role->id,
            branchId: BranchId::fromString($branch->id),
            scope: OrganizationScope::BRANCH,
            assignedAt: $assignedAt,
            assignedByUserId: $actor->id,
        );
        $repository = app(OrganizationAssignmentRepository::class);

        $repository->save($assignment);

        $updatedAssignedAt = now()->subMinutes(2)->toDateTimeImmutable();
        $assignment->updateDetails($updatedAssignedAt, 'Cobertura temporal');
        $repository->updateDetails($assignment);

        $updated = $repository->find($assignment->id());
        self::assertNotNull($updated);
        self::assertSame('Cobertura temporal', $updated->assignmentReason());

        self::assertTrue($repository->hasActiveDuplicate(
            $target->id,
            $role->id,
            BranchId::fromString($branch->id),
            OrganizationScope::BRANCH,
        ));
        self::assertCount(1, $repository->historyForUser($target->id));

        $assignment->close(
            now()->toDateTimeImmutable(),
            $actor->id,
            'Cambio de responsabilidad',
        );
        $repository->close($assignment);

        $persisted = $repository->find($assignment->id());
        self::assertNotNull($persisted);
        self::assertSame(AssignmentStatus::ENDED, $persisted->status());
        self::assertSame('Cambio de responsabilidad', $persisted->revocationReason());
        self::assertFalse($repository->hasActiveDuplicate(
            $target->id,
            $role->id,
            BranchId::fromString($branch->id),
            OrganizationScope::BRANCH,
        ));
        self::assertCount(1, $repository->historyForUser($target->id));
    }

    private function user(): User
    {
        $email = Str::uuid()->toString().'@example.test';

        return User::factory()->create([
            'email' => $email,
            'normalized_email' => $email,
            'state' => 'ACTIVE',
        ]);
    }

    private function branch(User $creator): BranchRecord
    {
        return BranchRecord::query()->create([
            'id' => Str::uuid()->toString(),
            'code' => 'TRC-ASG',
            'name' => 'Sucursal para asignaciones',
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'lock_version' => 0,
            'created_by' => $creator->id,
        ]);
    }
}
