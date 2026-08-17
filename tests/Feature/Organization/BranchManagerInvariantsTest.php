<?php

namespace Tests\Feature\Organization;

use App\Models\AccountInvitation;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use DB;

class BranchManagerInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Role $branchManagerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->admin = User::where('email', 'test@gmail.com')->firstOrFail();
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
        $this->assertStringContainsString('matriz no puede tener gerente', $response->json('message'));
    }

    public function test_branch_manager_cannot_be_assigned_if_active_manager_exists(): void
    {
        $branch = Branch::where('is_headquarters', false)->firstOrFail();
        
        $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name' => 'Manager One',
            'email' => 'manager1@test.com',
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $branch->id,
        ])->assertStatus(200);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name' => 'Manager Two',
            'email' => 'manager2@test.com',
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $branch->id,
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('ya cuenta con un gerente', $response->json('message'));
    }
    
    public function test_eligible_branches_query_excludes_unavailable_branches(): void
    {
        $branch = Branch::where('is_headquarters', false)->where('status', 'ACTIVE')->firstOrFail();
        
        $response = $this->actingAs($this->admin)->getJson('/api/v1/branches?eligible_for_manager=true');
        $response->assertStatus(200);
        $this->assertTrue(collect($response->json())->contains('id', $branch->id));
        
        $headquarters = Branch::where('is_headquarters', true)->firstOrFail();
        $this->assertFalse(collect($response->json())->contains('id', $headquarters->id));

        $this->actingAs($this->admin)->postJson('/api/v1/users', [
            'name' => 'Manager One',
            'email' => 'manager1@test.com',
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $branch->id,
        ])->assertStatus(200);

        $response2 = $this->actingAs($this->admin)->getJson('/api/v1/branches?eligible_for_manager=true');
        $this->assertFalse(collect($response2->json())->contains('id', $branch->id));
        
        $user = User::where('email', 'manager1@test.com')->firstOrFail();
        $assignment = UserRoleScope::where('user_id', $user->id)->firstOrFail();
        
        $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$user->id}/assignments/{$assignment->id}")->assertStatus(200);
        
        $response3 = $this->actingAs($this->admin)->getJson('/api/v1/branches?eligible_for_manager=true');
        $this->assertTrue(collect($response3->json())->contains('id', $branch->id));
    }
    
    public function test_concurrent_assignments_are_rejected(): void
    {
        $branch = Branch::where('is_headquarters', false)->firstOrFail();

        $user1 = clone $this->admin;
        $user1->id = Str::uuid()->toString();
        $user1->email = 'concurrent1@test.com';
        $user1->save();
        
        $user2 = clone $this->admin;
        $user2->id = Str::uuid()->toString();
        $user2->email = 'concurrent2@test.com';
        $user2->save();
        
        UserRoleScope::create([
            'id' => Str::uuid(),
            'user_id' => $user1->id,
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $branch->id,
            'status' => 'ACTIVE',
        ]);
        
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/unique_active_branch_manager/');
        
        UserRoleScope::create([
            'id' => Str::uuid(),
            'user_id' => $user2->id,
            'role_id' => $this->branchManagerRole->id,
            'branch_id' => $branch->id,
            'status' => 'ACTIVE',
        ]);
    }
}
