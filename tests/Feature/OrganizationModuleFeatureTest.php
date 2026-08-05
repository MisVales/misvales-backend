<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationModuleFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
        $this->artisan('db:seed', ['--class' => 'InitialGeneralManagerSeeder']);
    }

    public function test_general_manager_can_create_and_view_all_branches()
    {
        $manager = User::where('email', env('INITIAL_GENERAL_MANAGER_EMAIL'))->first();
        // Activate user and simulate login
        $manager->update(['state' => 'ACTIVE']);
        $this->actingAs($manager);

        // GM can view all branches
        $response = $this->getJson('/api/v1/branches');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json()); // MATRIZ

        // GM can create branch
        $response = $this->postJson('/api/v1/branches', [
            'code' => 'SUC01',
            'name' => 'Sucursal Norte',
        ]);
        $response->assertStatus(201);
        $this->assertEquals('SUC01', $response->json('code'));
    }

    public function test_branch_manager_can_only_view_own_branch()
    {
        $manager = User::factory()->create(['state' => 'ACTIVE']);
        $branch = Branch::where('code', 'MATRIZ')->first();
        $otherBranch = Branch::create(['id' => Str::uuid(), 'code' => 'SUC02', 'name' => 'Sur', 'is_headquarters' => false, 'status' => 'ACTIVE', 'created_by' => $manager->id]);

        $role = Role::where('code', 'branch_manager')->first();
        UserRoleScope::create([
            'id' => Str::uuid(),
            'user_id' => $manager->id,
            'role_id' => $role->id,
            'branch_id' => $otherBranch->id,
            'scope_type' => 'BRANCH',
            'status' => 'ACTIVE',
            'valid_from' => now(),
            'assigned_by' => $manager->id,
        ]);

        $this->actingAs($manager);

        // Branch manager views only their branch
        $response = $this->getJson('/api/v1/branches');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $this->assertEquals('SUC02', $response->json('0.code'));

        // Cannot create branch
        $response = $this->postJson('/api/v1/branches', [
            'code' => 'SUC03',
            'name' => 'Oeste',
        ]);
        $response->assertStatus(403);
    }

    public function test_matriz_cannot_be_deactivated()
    {
        $manager = User::where('email', env('INITIAL_GENERAL_MANAGER_EMAIL'))->first();
        $manager->update(['state' => 'ACTIVE']);
        $this->actingAs($manager);

        $matriz = Branch::where('code', 'MATRIZ')->first();

        $response = $this->patchJson("/api/v1/branches/{$matriz->id}/status", [
            'status' => 'INACTIVE',
        ]);
        $response->assertStatus(422);
    }

    public function test_assign_personnel_to_branch()
    {
        $manager = User::where('email', env('INITIAL_GENERAL_MANAGER_EMAIL'))->first();
        $manager->update(['state' => 'ACTIVE']);
        $this->actingAs($manager);

        $matriz = Branch::where('code', 'MATRIZ')->first();
        $employee = User::factory()->create(['state' => 'ACTIVE']);

        $response = $this->postJson("/api/v1/branches/{$matriz->id}/personnel", [
            'user_id' => $employee->id,
            'role_code' => 'coordinator',
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('user_role_scopes', [
            'user_id' => $employee->id,
            'branch_id' => $matriz->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_assign_distributor_to_coordinator_and_reassign()
    {
        $manager = User::where('email', env('INITIAL_GENERAL_MANAGER_EMAIL'))->first();
        $manager->update(['state' => 'ACTIVE']);
        $this->actingAs($manager);

        $matriz = Branch::where('code', 'MATRIZ')->first();
        
        $coord1 = User::factory()->create(['state' => 'ACTIVE']);
        $coord2 = User::factory()->create(['state' => 'ACTIVE']);
        $distributorId = Str::uuid()->toString(); // Simulated distributor user ID

        // Give them coordinator role
        $role = Role::where('code', 'coordinator')->first();
        UserRoleScope::create(['user_id' => $coord1->id, 'role_id' => $role->id, 'branch_id' => $matriz->id, 'scope_type' => 'BRANCH', 'status' => 'ACTIVE', 'valid_from' => now(), 'assigned_by' => $manager->id]);
        UserRoleScope::create(['user_id' => $coord2->id, 'role_id' => $role->id, 'branch_id' => $matriz->id, 'scope_type' => 'BRANCH', 'status' => 'ACTIVE', 'valid_from' => now(), 'assigned_by' => $manager->id]);

        // Assign distributor to coord1
        $response = $this->postJson("/api/v1/assignments/coordinator-distributor", [
            'coordinator_id' => $coord1->id,
            'distributor_id' => $distributorId,
            'branch_id' => $matriz->id,
            'assignment_reason' => 'First assignment'
        ]);
        $response->assertStatus(201);

        $assignment1Id = $response->json('id');

        // Reassign to coord2
        $response2 = $this->postJson("/api/v1/assignments/coordinator-distributor", [
            'coordinator_id' => $coord2->id,
            'distributor_id' => $distributorId,
            'branch_id' => $matriz->id,
            'assignment_reason' => 'Reassignment'
        ]);
        $response2->assertStatus(201);

        // Check history preserved
        $this->assertDatabaseHas('coordinator_distributor_assignments', [
            'id' => $assignment1Id,
            'status' => 'REASSIGNED',
        ]);

        $this->assertDatabaseHas('coordinator_distributor_assignments', [
            'id' => $response2->json('id'),
            'status' => 'ACTIVE',
        ]);
    }
}
