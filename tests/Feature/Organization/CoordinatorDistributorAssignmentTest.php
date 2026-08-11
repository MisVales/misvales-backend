<?php

namespace Tests\Feature\Organization;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\Role;
use App\Models\SolicitudDistribuidora;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CoordinatorDistributorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_branch_manager_reassigns_distributor_and_preserves_history(): void
    {
        $creator = $this->user();
        $branch = $this->branch($creator);
        $manager = $this->user();
        $coordinatorA = $this->user();
        $coordinatorB = $this->user();
        $this->assignment($manager, $creator, 'branch_manager', $branch);
        $this->assignment($coordinatorA, $creator, 'coordinator', $branch);
        $this->assignment($coordinatorB, $creator, 'coordinator', $branch);
        $distributor = $this->distributor($creator, $branch, $coordinatorA);
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/assignments/distributors?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $distributor->id);

        $first = $this->postJson('/api/v1/assignments/coordinator-distributor', [
            'branch_id' => $branch->id,
            'distributor_id' => $distributor->id,
            'coordinator_id' => $coordinatorA->id,
            'assignment_reason' => 'Asignación inicial',
        ])->assertCreated();

        $second = $this->postJson('/api/v1/assignments/coordinator-distributor', [
            'branch_id' => $branch->id,
            'distributor_id' => $distributor->id,
            'coordinator_id' => $coordinatorB->id,
            'assignment_reason' => 'Cambio de cartera',
        ])->assertCreated()
            ->assertJsonPath('data.coordinator_id', $coordinatorB->id);

        $this->assertDatabaseHas('coordinator_distributor_assignments', [
            'id' => $first->json('data.id'),
            'status' => 'REASSIGNED',
        ]);
        $this->assertDatabaseHas('coordinator_distributor_assignments', [
            'id' => $second->json('data.id'),
            'status' => 'ACTIVE',
        ]);
        $this->getJson("/api/v1/assignments/coordinator-distributor?branch_id={$branch->id}&include_history=true")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_active_distributor_cannot_be_left_without_coordinator(): void
    {
        $manager = $this->user();
        $branch = $this->branch($manager);
        $coordinator = $this->user();
        $this->assignment($manager, $manager, 'general_manager');
        $this->assignment($coordinator, $manager, 'coordinator', $branch);
        $distributor = $this->distributor($manager, $branch, $coordinator);
        Sanctum::actingAs($manager);

        $assignment = CoordinatorDistributorAssignment::query()->create([
            'id' => Str::uuid()->toString(),
            'coordinator_id' => $coordinator->id,
            'distributor_id' => $distributor->id,
            'branch_id' => $branch->id,
            'valid_from' => now()->subHour(),
            'status' => 'ACTIVE',
            'assigned_by' => $manager->id,
        ]);

        $this->deleteJson("/api/v1/assignments/coordinator-distributor/{$assignment->id}", [
            'end_reason' => 'Retiro sin reemplazo',
        ])->assertConflict()
            ->assertJsonPath('code', 'ACTIVE_DISTRIBUTOR_REQUIRES_COORDINATOR');

        $this->assertDatabaseHas('coordinator_distributor_assignments', [
            'id' => $assignment->id,
            'status' => 'ACTIVE',
            'valid_to' => null,
        ]);
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
            'code' => 'TRC-'.Str::upper(Str::random(4)),
            'name' => 'Sucursal de prueba',
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'lock_version' => 0,
            'created_by' => $creator->id,
        ]);
    }

    private function assignment(User $user, User $actor, string $roleCode, ?BranchRecord $branch = null): void
    {
        UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'branch_id' => $branch?->id,
            'scope_type' => $branch === null ? 'GLOBAL' : 'BRANCH',
            'status' => 'ACTIVE',
            'assigned_by_user_id' => $actor->id,
            'assigned_at' => now()->subDay(),
        ]);
    }

    private function distributor(User $creator, BranchRecord $branch, User $coordinator): Distribuidora
    {
        $solicitud = SolicitudDistribuidora::query()->forceCreate([
            'id' => Str::uuid()->toString(),
            'application_number' => 'SOL-2026-'.random_int(100000, 999999),
            'branch_id' => $branch->id,
            'coordinator_id' => $coordinator->id,
            'status' => 'ACTIVE',
            'section_declarations' => [],
            'created_by' => $creator->id,
            'lock_version' => 1,
        ]);

        return Distribuidora::query()->forceCreate([
            'id' => Str::uuid()->toString(),
            'application_id' => $solicitud->id,
            'user_id' => $creator->id,
            'distributor_number' => 'DIS-2026-'.random_int(100000, 999999),
            'branch_id' => $branch->id,
            'status' => 'ACTIVE',
            'activated_at' => now(),
            'activated_by' => $creator->id,
            'lock_version' => 1,
        ]);
    }
}
