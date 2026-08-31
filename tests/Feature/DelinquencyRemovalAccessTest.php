<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DelinquencyRemovalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_admin_can_list_delinquency_removal_requests(): void
    {
        $admin = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', 'admin')->firstOrFail();
        $admin->roleScopes()->create([
            'role_id' => $role->id,
            'scope_type' => 'GLOBAL',
            'status' => 'ACTIVE',
        ]);

        self::assertTrue($admin->hasPermissionTo('delinquency_removal.decide_global'));

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/delinquency-removal-requests')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
