<?php

namespace Tests\Feature\Organization;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BranchManagerInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Role $branchManagerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create([
            'email' => 'test@gmail.com',
            'normalized_email' => 'test@gmail.com',
        ]);
        $this->admin->assignRole('general_manager');
        Branch::factory()->create([
            'code' => 'MATRIZ',
            'is_headquarters' => true,
            'created_by' => $this->admin->id,
        ]);
        Branch::factory()->create([
            'is_headquarters' => false,
            'created_by' => $this->admin->id,
        ]);

        $this->branchManagerRole = Role::where('code', 'branch_manager')->firstOrFail();
    }

    public function test_branch_manager_cannot_be_assigned_to_headquarters(): void
    {
        $headquarters = Branch::where('is_headquarters', true)->firstOrFail();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name' => 'Test Manager',
            'email' => 'test-hq-manager@test.com',
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $headquarters->id,
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('matriz no puede tener gerente', $response->json('error.message'));
    }

    public function test_branch_manager_cannot_be_assigned_if_active_manager_exists(): void
    {
        $branch = Branch::where('is_headquarters', false)->firstOrFail();

        $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name' => 'Manager One',
            'email' => 'manager1@test.com',
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $branch->id,
        ])->assertCreated();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name' => 'Manager Two',
            'email' => 'manager2@test.com',
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $branch->id,
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('ya cuenta con un gerente', $response->json('error.message'));
    }

    public function test_manager_invitation_catalog_omits_branches_with_an_active_manager(): void
    {
        $occupiedBranch = Branch::where('is_headquarters', false)->firstOrFail();
        $availableBranch = Branch::factory()->create([
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);
        $manager = User::factory()->create();

        UserRoleScope::create([
            'id' => Str::uuid(),
            'user_id' => $manager->id,
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $occupiedBranch->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/branches?eligible_for_manager=true');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($occupiedBranch->id));
        $this->assertTrue($ids->contains($availableBranch->id));
    }

    public function test_current_branch_directory_lists_active_branches_with_pagination(): void
    {
        $branch = Branch::where('is_headquarters', false)->where('status', 'ACTIVE')->firstOrFail();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/branches?status=ACTIVE');
        $response->assertStatus(200);
        $this->assertTrue(collect($response->json('data'))->contains('id', $branch->id));

        $headquarters = Branch::where('is_headquarters', true)->firstOrFail();
        $this->assertTrue(collect($response->json('data'))->contains('id', $headquarters->id));
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total']]);
    }

    public function test_concurrent_assignments_are_rejected(): void
    {
        $branch = Branch::where('is_headquarters', false)->firstOrFail();

        $user1 = User::factory()->create([
            'email' => 'concurrent1@test.com',
            'normalized_email' => 'concurrent1@test.com',
        ]);
        $user2 = User::factory()->create([
            'email' => 'concurrent2@test.com',
            'normalized_email' => 'concurrent2@test.com',
        ]);

        UserRoleScope::create([
            'id' => Str::uuid(),
            'user_id' => $user1->id,
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $branch->id,
            'status' => 'ACTIVE',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/sucursal ya cuenta con un gerente activo/i');

        UserRoleScope::create([
            'id' => Str::uuid(),
            'user_id' => $user2->id,
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $branch->id,
            'status' => 'ACTIVE',
        ]);
    }
}
