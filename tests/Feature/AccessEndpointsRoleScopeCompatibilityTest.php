<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AccessEndpointsRoleScopeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);

        $this->manager = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', 'general_manager')->firstOrFail();

        UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $this->manager->id,
            'role_id' => $role->id,
            'branch_id' => null,
            'assigned_by_user_id' => $this->manager->id,
            'assigned_at' => now(),
            'revoked_at' => null,
            'scope_type' => 'GLOBAL',
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($this->manager);
    }

    public function test_users_index_uses_the_canonical_role_scope_lifecycle(): void
    {
        $this->getJson('/api/v1/users?page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->manager->id);
    }

    public function test_roles_index_uses_the_canonical_role_scope_lifecycle(): void
    {
        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonFragment(['code' => 'general_manager']);
    }

    public function test_role_helpers_use_the_canonical_assignment_columns(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);

        $user->assignRole('cashier');

        self::assertTrue($user->hasRole('cashier'));
        self::assertTrue($user->is_active);
        $this->assertDatabaseHas('user_role_scopes', [
            'user_id' => $user->id,
            'assigned_by_user_id' => $user->id,
            'status' => 'ACTIVE',
            'revoked_at' => null,
        ]);
    }

    public function test_access_read_endpoints_are_not_broken_by_role_scope_authorization(): void
    {
        $role = Role::query()->where('code', 'general_manager')->firstOrFail();

        foreach ([
            '/api/v1/permissions',
            "/api/v1/roles/{$role->id}",
            "/api/v1/users/{$this->manager->id}",
            '/api/v1/invitations',
            '/api/v1/security-events',
            '/api/v1/me/sessions',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertOk();
        }
    }
}
