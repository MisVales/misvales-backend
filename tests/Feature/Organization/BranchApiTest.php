<?php

namespace Tests\Feature\Organization;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Role;
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

final class BranchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
        $this->app->instance(AddressValidator::class, new FakeAddressValidator);
    }

    public function test_general_manager_can_create_a_branch(): void
    {
        $manager = $this->userWithRole('general_manager');
        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/v1/branches', [
            'name' => 'Sucursal Torreón Norte',
            'address' => 'Blvd. Independencia 100, Torreón, Coahuila, 27000',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.address', 'Blvd. Independencia 100, Torreón, Coahuila, 27000')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.lock_version', 0);
        $this->assertMatchesRegularExpression('/\ASUC-\d{3,}\z/', $response->json('data.code'));

        $this->assertDatabaseHas('branches', [
            'code' => $response->json('data.code'),
            'created_by' => $manager->id,
        ]);
    }

    public function test_branch_manager_cannot_create_a_branch(): void
    {
        $generalManager = $this->userWithRole('general_manager');
        $branch = $this->branch($generalManager, 'TRC-01');
        $branchManager = $this->userWithRole('branch_manager', $branch->id);
        Sanctum::actingAs($branchManager);

        $this->postJson('/api/v1/branches', [
            'name' => 'Sucursal Torreón Norte',
            'address' => 'Blvd. Independencia 100, Torreón, Coahuila, 27000',
        ])->assertForbidden();

        $this->assertDatabaseMissing('branches', ['code' => 'TRC-02']);
    }

    public function test_branch_manager_only_lists_the_assigned_branch(): void
    {
        $generalManager = $this->userWithRole('general_manager');
        $assignedBranch = $this->branch($generalManager, 'TRC-01');
        $this->branch($generalManager, 'TRC-02');
        $branchManager = $this->userWithRole('branch_manager', $assignedBranch->id);
        Sanctum::actingAs($branchManager);

        $response = $this->getJson('/api/v1/branches');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedBranch->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_admin_lists_all_branches_in_read_only_mode(): void
    {
        $generalManager = $this->userWithRole('general_manager');
        $this->branch($generalManager, 'TRC-01');
        $this->branch($generalManager, 'TRC-02');
        $admin = $this->userWithRole('admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/branches', [
            'name' => 'Sucursal Torreón Sur',
            'address' => 'Av. Juárez 200, Torreón, Coahuila, 27000',
        ])->assertForbidden();
    }

    public function test_update_accepts_if_match_and_rejects_a_stale_version(): void
    {
        $manager = $this->userWithRole('general_manager');
        $branch = $this->branch($manager, 'TRC-01');
        Sanctum::actingAs($manager);

        $this->withHeader('If-Match', '"0"')
            ->patchJson("/api/v1/branches/{$branch->id}", [
                'name' => 'Sucursal Torreón Centro Renovada',
                'address' => 'Av. Hidalgo 300, Torreón, Coahuila, 27000',
            ])
            ->assertOk()
            ->assertJsonPath('data.lock_version', 1);

        $this->patchJson("/api/v1/branches/{$branch->id}", [
            'name' => 'Cambio obsoleto',
            'address' => 'Av. Hidalgo 301, Torreón, Coahuila, 27000',
            'lock_version' => 0,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');
    }

    public function test_general_manager_can_deactivate_and_reactivate_an_empty_branch(): void
    {
        $manager = $this->userWithRole('general_manager');
        $branch = $this->branch($manager, 'TRC-01');
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/branches/{$branch->id}/deactivate", [
            'lock_version' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'INACTIVE')
            ->assertJsonPath('data.lock_version', 1);

        $this->withHeader('If-Match', '"1"')
            ->postJson("/api/v1/branches/{$branch->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.lock_version', 2);
    }

    public function test_headquarters_cannot_be_deactivated(): void
    {
        $manager = $this->userWithRole('general_manager');
        $headquarters = $this->branch($manager, 'TOR-MATRIZ', headquarters: true);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/branches/{$headquarters->id}/deactivate", [
            'lock_version' => 0,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'HEADQUARTERS_BRANCH_PROTECTED');

        $this->assertDatabaseHas('branches', [
            'id' => $headquarters->id,
            'status' => 'ACTIVE',
            'lock_version' => 0,
        ]);
    }

    public function test_branch_with_active_assignments_cannot_be_deactivated(): void
    {
        $manager = $this->userWithRole('general_manager');
        $branch = $this->branch($manager, 'TRC-01');
        $this->userWithRole('branch_manager', $branch->id);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/branches/{$branch->id}/deactivate", [
            'lock_version' => 0,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'BRANCH_HAS_ACTIVE_ASSIGNMENTS');
    }

    private function userWithRole(string $roleCode, ?string $branchId = null): User
    {
        $email = Str::uuid()->toString().'@example.test';
        $user = User::factory()->create([
            'email' => $email,
            'normalized_email' => $email,
            'state' => 'ACTIVE',
        ]);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        UserRoleScope::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
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
