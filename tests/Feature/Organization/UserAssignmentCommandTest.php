<?php

namespace Tests\Feature\Organization;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UserAssignmentCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_general_manager_assigns_and_reassigns_personnel_preserving_history(): void
    {
        $manager = $this->user();
        $target = $this->user();
        $generalManagerRole = $this->role('general_manager');
        $coordinatorRole = $this->role('coordinator');
        $this->roleScope($manager, $generalManagerRole);
        $firstBranch = $this->branch($manager, 'TRC-01');
        $secondBranch = $this->branch($manager, 'TRC-02');
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $coordinatorRole->id,
            'branch_id' => $firstBranch->id,
            'scope' => 'BRANCH',
        ])->assertCreated()->assertJsonPath('assignment.status', 'ACTIVE');

        $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $coordinatorRole->id,
            'branch_id' => $secondBranch->id,
            'scope' => 'BRANCH',
        ])->assertCreated();

        $this->assertDatabaseHas('user_role_scopes', [
            'user_id' => $target->id,
            'role_id' => $coordinatorRole->id,
            'branch_id' => $firstBranch->id,
            'status' => 'ENDED',
            'revocation_reason' => 'REASSIGNED',
        ]);
        $this->assertDatabaseHas('user_role_scopes', [
            'user_id' => $target->id,
            'role_id' => $coordinatorRole->id,
            'branch_id' => $secondBranch->id,
            'status' => 'ACTIVE',
        ]);

        $this->getJson("/api/v1/users/{$target->id}/assignments?include_history=true")
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_it_rejects_a_duplicate_and_a_blocked_user(): void
    {
        $manager = $this->user();
        $target = $this->user();
        $blockedTarget = $this->user('BLOCKED');
        $this->roleScope($manager, $this->role('general_manager'));
        $role = $this->role('cashier');
        $branch = $this->branch($manager, 'TRC-01');
        Sanctum::actingAs($manager);
        $payload = ['role_id' => $role->id, 'branch_id' => $branch->id, 'scope' => 'BRANCH'];

        $this->postJson("/api/v1/users/{$target->id}/assignments", $payload)->assertCreated();
        $this->postJson("/api/v1/users/{$target->id}/assignments", $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'DUPLICATE_ACTIVE_ASSIGNMENT');
        $this->postJson("/api/v1/users/{$blockedTarget->id}/assignments", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'USER_NOT_ASSIGNABLE');
    }

    public function test_branch_manager_can_only_assign_operational_roles_in_own_branch(): void
    {
        $creator = $this->user();
        $branchManager = $this->user();
        $target = $this->user();
        $ownBranch = $this->branch($creator, 'TRC-01');
        $otherBranch = $this->branch($creator, 'TRC-02');
        $this->roleScope($branchManager, $this->role('branch_manager'), $ownBranch->id);
        $cashier = $this->role('cashier');
        Sanctum::actingAs($branchManager);

        $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $cashier->id,
            'branch_id' => $ownBranch->id,
            'scope' => 'BRANCH',
        ])->assertCreated();

        $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $cashier->id,
            'branch_id' => $otherBranch->id,
            'scope' => 'BRANCH',
        ])->assertForbidden();
    }

    public function test_general_manager_logically_ends_an_assignment(): void
    {
        $manager = $this->user();
        $target = $this->user();
        $this->roleScope($manager, $this->role('general_manager'));
        $branch = $this->branch($manager, 'TRC-01');
        Sanctum::actingAs($manager);

        $created = $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $this->role('cashier')->id,
            'branch_id' => $branch->id,
            'scope' => 'BRANCH',
        ])->assertCreated();
        $assignmentId = $created->json('assignment.id');

        $this->deleteJson("/api/v1/users/{$target->id}/assignments/{$assignmentId}")
            ->assertOk()
            ->assertJsonPath('message', 'Asignación revocada exitosamente.');

        $this->assertDatabaseHas('user_role_scopes', [
            'id' => $assignmentId,
            'status' => 'REVOKED',
            'revocation_reason' => 'REVOKED_BY_ADMIN',
        ]);
    }

    public function test_general_manager_updates_active_assignment_details_but_not_closed_history(): void
    {
        $manager = $this->user();
        $target = $this->user();
        $this->roleScope($manager, $this->role('general_manager'));
        $branch = $this->branch($manager, 'TRC-01');
        Sanctum::actingAs($manager);

        $created = $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $this->role('cashier')->id,
            'branch_id' => $branch->id,
            'scope' => 'BRANCH',
        ])->assertCreated();
        $assignmentId = $created->json('assignment.id');
        $assignedAt = now()->subMinutes(5)->toISOString();

        $this->patchJson("/api/v1/users/{$target->id}/assignments/{$assignmentId}", [
            'assigned_at' => $assignedAt,
            'assignment_reason' => 'Cobertura de turno vespertino',
        ])->assertOk()
            ->assertJsonPath('message', 'Asignación actualizada exitosamente.')
            ->assertJsonPath('assignment.assignment_reason', 'Cobertura de turno vespertino');

        $this->assertDatabaseHas('user_role_scopes', [
            'id' => $assignmentId,
            'assignment_reason' => 'Cobertura de turno vespertino',
            'status' => 'ACTIVE',
        ]);

        $this->deleteJson("/api/v1/users/{$target->id}/assignments/{$assignmentId}")
            ->assertOk();

        $this->patchJson("/api/v1/users/{$target->id}/assignments/{$assignmentId}", [
            'assignment_reason' => 'No debe modificar el historial',
        ])->assertConflict()
            ->assertJsonPath('error.code', 'ASSIGNMENT_ALREADY_CLOSED');

        $this->assertDatabaseHas('user_role_scopes', [
            'id' => $assignmentId,
            'assignment_reason' => 'Cobertura de turno vespertino',
            'status' => 'REVOKED',
        ]);
    }

    public function test_inactive_branch_and_invalid_role_scope_return_stable_errors(): void
    {
        $manager = $this->user();
        $target = $this->user();
        $this->roleScope($manager, $this->role('general_manager'));
        $branch = $this->branch($manager, 'TRC-01');
        $branch->forceFill(['status' => 'INACTIVE'])->save();
        $cashier = $this->role('cashier');
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $cashier->id,
            'branch_id' => $branch->id,
            'scope' => 'BRANCH',
        ])->assertConflict()
            ->assertJsonPath('error.code', 'BRANCH_INACTIVE');

        $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $cashier->id,
            'scope' => 'GLOBAL',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'ROLE_SCOPE_NOT_ALLOWED');
    }

    private function user(string $state = 'ACTIVE'): User
    {
        $email = Str::uuid()->toString().'@example.test';

        return User::factory()->create(['email' => $email, 'normalized_email' => $email, 'state' => $state]);
    }

    private function role(string $code): Role
    {
        return Role::query()->where('code', $code)->firstOrFail();
    }

    private function roleScope(User $user, Role $role, ?string $branchId = null): void
    {
        UserRoleScope::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'scope_type' => $branchId === null ? 'GLOBAL' : 'BRANCH',
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
        ]);
    }

    private function branch(User $creator, string $code): BranchRecord
    {
        return BranchRecord::query()->create([
            'id' => Str::uuid()->toString(), 'code' => $code, 'name' => "Sucursal {$code}",
            'is_headquarters' => false, 'status' => 'ACTIVE', 'lock_version' => 0, 'created_by' => $creator->id,
        ]);
    }
}
