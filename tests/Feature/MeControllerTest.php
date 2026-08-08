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
}
