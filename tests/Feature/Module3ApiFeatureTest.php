<?php

namespace Tests\Feature;

use App\Enums\VersionStatus;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\ConfigurationDefinition;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Module3ApiFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
        $adminRole = Role::query()->where('code', 'general_manager')->firstOrFail();

        // Crear usuarios
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'normalized_email' => 'ADMIN@TEST.COM',
            'password' => bcrypt('password'),
            'state' => 'ACTIVE',
        ]);
        UserRoleScope::query()->create([
            'user_id' => $this->admin->id,
            'role_id' => $adminRole->id,
            'scope_type' => 'GLOBAL',
            'assigned_by_user_id' => $this->admin->id,
            'assigned_at' => now(),
        ]);

        $this->cashier = User::create([
            'name' => 'Cashier User',
            'email' => 'cashier@test.com',
            'normalized_email' => 'CASHIER@TEST.COM',
            'password' => bcrypt('password'),
            'state' => 'ACTIVE',
        ]);
    }

    public function test_reject_unauthorized_catalog_creation()
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/configurations', [
            'key' => 'TEST_KEY',
            'name' => 'Test',
            'value_type' => 'STRING',
            'value' => 'value',
            'effective_from' => now()->addDay()->toIso8601String(),
            'reason' => 'Test',
        ], ['X-Request-Id' => Str::uuid()->toString()]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'PERMISSION_DENIED']);
    }

    public function test_admin_can_create_configuration()
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/configurations', [
            'key' => 'MAX_LOAN',
            'name' => 'Préstamo Máximo',
            'value_type' => 'INTEGER',
            'value' => '5000',
            'effective_from' => now()->addDay()->toIso8601String(),
            'reason' => 'Test setup',
        ], ['X-Request-Id' => Str::uuid()->toString()]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('configuration_definitions', ['key' => 'MAX_LOAN']);
    }

    public function test_publish_version_requires_reason()
    {
        $def = ConfigurationDefinition::create([
            'key' => 'A',
            'name' => 'A',
            'value_type' => 'STRING',
            'status' => 'ACTIVE',
            'created_by' => $this->admin->id,
        ]);
        $ver = $def->versions()->create([
            'version' => 1,
            'value' => 'Val',
            'status' => VersionStatus::DRAFT,
            'effective_from' => now()->addDay(),
            'created_by' => $this->admin->id,
            'reason' => 'Initial draft',
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/configuration-versions/{$ver->id}/publish", [
            'lock_version' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_cannot_create_incomplete_product()
    {
        // Producto base
        $response = $this->actingAs($this->admin)->postJson('/api/v1/products', [
            'code' => 'PROD01',
            'reason' => 'Nuevo',
        ], ['X-Request-Id' => Str::uuid()->toString()]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'nominal_amount',
                'loan_commission_percentage',
                'simple_interest_percentage',
                'insurance_amount',
                'fortnights_count',
                'effective_from',
            ]);
    }

    public function test_reject_product_amount_not_multiple_of_100()
    {
        $productId = Str::uuid()->toString(); // Assuming route requires valid UUID, not necessarily existing for validation
        $response = $this->actingAs($this->admin)->postJson("/api/v1/products/{$productId}/versions", [
            'name' => 'Producto 1',
            'nominal_amount' => 1050.50, // Not multiple of 100
            'effective_from' => now()->addDay()->toIso8601String(),
            'reason' => 'Init',
        ], ['X-Request-Id' => Str::uuid()->toString()]);

        $response->assertStatus(422);
    }
}
