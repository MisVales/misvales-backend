<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class BranchManagerAdministrationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_branch_manager_cannot_access_administration_endpoints(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', 'branch_manager')->firstOrFail();
        $branch = BranchRecord::query()->create([
            'name' => 'Sucursal de prueba',
            'code' => 'SUC-TEST',
            'status' => 'ACTIVE',
            'is_headquarters' => false,
            'lock_version' => 0,
            'created_by' => $user->id,
        ]);

        UserRoleScope::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => 'BRANCH',
            'branch_id' => $branch->id,
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($user);

        self::assertFalse($user->hasPermissionTo('catalogs.view_published'));
        self::assertFalse($user->hasPermissionTo('catalogs.view_history'));

        $this->getJson('/api/v1/configurations')->assertForbidden();
        $this->getJson('/api/v1/categories')->assertForbidden();
        $this->getJson('/api/v1/products')->assertForbidden();
        $this->postJson('/api/v1/configurations', [])->assertForbidden();
        $this->postJson('/api/v1/categories', [])->assertForbidden();
        $this->postJson('/api/v1/products', [])->assertForbidden();
    }
}
