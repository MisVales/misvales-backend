<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Role;
use App\Models\Distribuidora;
use App\Models\SolicitudDistribuidora;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Application\Branches\AddressValidator;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeAddressValidator;
use Tests\TestCase;

final class OrganizationModuleFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
        $this->app->instance(AddressValidator::class, new FakeAddressValidator);
    }

    public function test_general_manager_can_create_and_view_all_branches(): void
    {
        $manager = $this->userWithRole('general_manager');
        $this->branch($manager, 'MATRIZ', headquarters: true);
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $response = $this->postJson('/api/v1/branches', [
            'name' => 'Sucursal Norte',
            'address' => 'Blvd. Independencia 100, Torreón, Coahuila, 27000',
        ])->assertCreated();
        $this->assertMatchesRegularExpression('/\ASUC-\d{3,}\z/', $response->json('data.code'));
    }

    public function test_branch_manager_can_only_view_own_branch(): void
    {
        $creator = $this->userWithRole('general_manager');
        $assignedBranch = $this->branch($creator, 'SUC02');
        $this->branch($creator, 'SUC03');
        $manager = $this->userWithRole('branch_manager', $assignedBranch);
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SUC02');

        $this->postJson('/api/v1/branches', [
            'name' => 'Sucursal Oeste',
            'address' => 'Av. Juárez 200, Torreón, Coahuila, 27000',
        ])->assertForbidden();
    }

    public function test_matriz_cannot_be_deactivated(): void
    {
        $manager = $this->userWithRole('general_manager');
        $headquarters = $this->branch($manager, 'MATRIZ', headquarters: true);
        Sanctum::actingAs($manager);

        $this->patchJson("/api/v1/branches/{$headquarters->id}/status", [
            'status' => 'INACTIVE',
            'lock_version' => 0,
        ])->assertConflict()
            ->assertJsonPath('code', 'HEADQUARTERS_BRANCH_PROTECTED');
    }

    public function test_assign_personnel_to_branch(): void
    {
        $manager = $this->userWithRole('general_manager');
        $branch = $this->branch($manager, 'MATRIZ', headquarters: true);
        $employee = $this->user();
        $coordinator = Role::query()->where('code', 'coordinator')->firstOrFail();
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/users/{$employee->id}/assignments", [
            'role_id' => $coordinator->id,
            'branch_id' => $branch->id,
            'scope' => 'BRANCH',
            'assignment_reason' => 'Cobertura operativa',
        ])->assertCreated();

        $this->assertDatabaseHas('user_role_scopes', [
            'user_id' => $employee->id,
            'branch_id' => $branch->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_assign_distributor_to_coordinator_and_reassign(): void
    {
        $manager = $this->userWithRole('general_manager');
        $branch = $this->branch($manager, 'MATRIZ', headquarters: true);
        $coordinatorA = $this->userWithRole('coordinator', $branch);
        $coordinatorB = $this->userWithRole('coordinator', $branch);
        $application = SolicitudDistribuidora::query()->forceCreate([
            'id' => Str::uuid()->toString(),
            'application_number' => 'SOL-2026-000001',
            'branch_id' => $branch->id,
            'coordinator_id' => $coordinatorA->id,
            'status' => 'ACTIVE',
            'section_declarations' => [],
            'created_by' => $manager->id,
            'lock_version' => 1,
        ]);
        $distributor = Distribuidora::factory()->active()->create([
            'application_id' => $application->id,
            'branch_id' => $branch->id,
        ]);
        Sanctum::actingAs($manager);

        $first = $this->postJson('/api/v1/assignments/coordinator-distributor', [
            'coordinator_id' => $coordinatorA->id,
            'distributor_id' => $distributor->id,
            'branch_id' => $branch->id,
            'assignment_reason' => 'Asignación inicial',
        ])->assertCreated();

        $second = $this->postJson('/api/v1/assignments/coordinator-distributor', [
            'coordinator_id' => $coordinatorB->id,
            'distributor_id' => $distributor->id,
            'branch_id' => $branch->id,
            'assignment_reason' => 'Reasignación',
        ])->assertCreated();

        $this->assertDatabaseHas('coordinator_distributor_assignments', [
            'id' => $first->json('data.id'),
            'status' => 'REASSIGNED',
        ]);
        $this->assertDatabaseHas('coordinator_distributor_assignments', [
            'id' => $second->json('data.id'),
            'status' => 'ACTIVE',
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

    private function userWithRole(string $roleCode, ?BranchRecord $branch = null): User
    {
        $user = $this->user();
        UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'branch_id' => $branch?->id,
            'scope_type' => $branch === null ? 'GLOBAL' : 'BRANCH',
            'status' => 'ACTIVE',
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now()->subDay(),
        ]);

        return $user;
    }

    private function branch(User $creator, string $code, bool $headquarters = false): BranchRecord
    {
        return BranchRecord::query()->create([
            'id' => Str::uuid()->toString(),
            'code' => $code,
            'name' => "Sucursal {$code}",
            'is_headquarters' => $headquarters,
            'status' => 'ACTIVE',
            'lock_version' => 0,
            'created_by' => $creator->id,
        ]);
    }
}
