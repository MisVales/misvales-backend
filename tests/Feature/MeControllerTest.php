<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Branch;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_active_role_scopes_using_the_canonical_lifecycle_columns(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);

        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', 'general_manager')->firstOrFail();

        UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => null,
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
            'revoked_at' => null,
            'scope_type' => 'GLOBAL',
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('scopes.0.role', 'general_manager');
    }

    public function test_me_returns_the_effective_distributor_scope_created_by_activation(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);

        $user = User::factory()->create(['state' => 'ACTIVE']);
        $branch = Branch::factory()->create();
        $role = Role::query()->where('code', 'distributor')->firstOrFail();
        $application = DistributorApplication::factory()->create(['branch_id' => $branch->id]);
        $distributor = Distribuidora::query()->create([
            'application_id' => $application->id,
            'user_id' => $user->id,
            'distributor_number' => 'DIS-2026-999999',
            'branch_id' => $branch->id,
        ]);

        UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
            'revoked_at' => null,
            'scope_type' => 'DISTRIBUTOR',
            'scope_id' => $distributor->id,
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('scopes.0.role', 'distributor')
            ->assertJsonPath('scopes.0.scope_type', 'DISTRIBUTOR')
            ->assertJsonPath('scopes.0.scope_id', $distributor->id)
            ->assertJsonFragment(['clients.create']);
    }

    public function test_me_includes_the_authorized_branch_name_in_a_branch_scope(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);

        $user = User::factory()->create(['state' => 'ACTIVE']);
        $branch = Branch::factory()->create(['name' => 'Sucursal Matamoros', 'code' => 'MAT']);
        $role = Role::query()->where('code', 'coordinator')->firstOrFail();
        UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
            'scope_type' => 'BRANCH',
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('scopes.0.branch_name', 'Sucursal Matamoros')
            ->assertJsonPath('scopes.0.branch_code', 'MAT');
    }
}
