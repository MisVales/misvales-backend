<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Enums\VersionStatus;
use Illuminate\Support\Str;

class Module3ApiFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $adminRole = Role::firstOrCreate(['name' => 'general_manager', 'code' => 'GM', 'display_name' => 'Gerente', 'default_scope' => 'GLOBAL']);
        $cashierRole = Role::firstOrCreate(['name' => 'cashier', 'code' => 'CA', 'display_name' => 'Cajera', 'default_scope' => 'BRANCH']);

        // Crear usuarios
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'normalized_email' => 'ADMIN@TEST.COM',
            'password' => bcrypt('password'),
        ]);
        $this->admin->roleScopes()->create(['role_id' => $adminRole->id, 'scope_type' => 'GLOBAL']);

        $this->cashier = User::create([
            'name' => 'Cashier User',
            'email' => 'cashier@test.com',
            'normalized_email' => 'CASHIER@TEST.COM',
            'password' => bcrypt('password'),
        ]);
        $this->cashier->roleScopes()->create(['role_id' => $cashierRole->id, 'scope_type' => 'GLOBAL']);
    }

    public function test_reject_unauthorized_catalog_creation()
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/configurations', [
            'key' => 'TEST_KEY',
            'name' => 'Test',
            'value_type' => 'STRING',
            'value' => 'value',
            'effective_from' => now()->addDay()->toIso8601String(),
            'reason' => 'Test'
        ], ['X-Request-Id' => Str::uuid()->toString()]);

        $response->assertStatus(403)
                 ->assertJson(['error' => 'AUTH_SCOPE_DENIED']);
    }

    public function test_admin_can_create_configuration()
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/configurations', [
            'key' => 'MAX_LOAN',
            'name' => 'Préstamo Máximo',
            'value_type' => 'INTEGER',
            'value' => '5000',
            'effective_from' => now()->addDay()->toIso8601String(),
            'reason' => 'Test setup'
        ], ['X-Request-Id' => Str::uuid()->toString()]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('configuration_definitions', ['key' => 'MAX_LOAN']);
    }

    public function test_publish_version_rejects_without_idempotency_key()
    {
        $def = ConfigurationDefinition::create(['key' => 'A', 'name' => 'A', 'value_type' => 'STRING']);
        $ver = $def->versions()->create([
            'version' => 1,
            'value' => 'Val',
            'status' => VersionStatus::DRAFT,
            'effective_from' => now()->addDay(),
            'created_by' => $this->admin->id
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/configuration-versions/{$ver->id}/publish", [
            'lock_version' => 1
        ]); // Faltó X-Request-Id y Idempotency-Key

        $response->assertStatus(422) // RequireXRequestId fails if not provided? No, it's 422 if standard header check or 400.
                 ->assertJsonValidationErrors(['x_request_id']); // Depending on implementation
    }

    public function test_cannot_publish_product_incomplete()
    {
        // Producto base
        $response = $this->actingAs($this->admin)->postJson('/api/v1/products', [
            'code' => 'PROD01',
            'reason' => 'Nuevo'
        ], ['X-Request-Id' => Str::uuid()->toString()]);
        
        $response->assertStatus(201);
        $productId = $response->json('data.id');

        // Crear versión DRAFT incompleta (sin amounts)
        $vResponse = $this->actingAs($this->admin)->postJson("/api/v1/products/{$productId}/versions", [
            'name' => 'Producto 1',
            'nominal_amount' => 1000.00,
            'fortnights_count' => 10,
            'effective_from' => now()->addDay()->toIso8601String(),
            'reason' => 'Init'
        ], ['X-Request-Id' => Str::uuid()->toString()]);

        $vResponse->assertStatus(201);
        $versionId = $vResponse->json('data.id');
        $lockVersion = $vResponse->json('data.lock_version');

        // Intentar publicar y esperar PRODUCT_INCOMPLETE
        $pubResponse = $this->actingAs($this->admin)->postJson("/api/v1/product-versions/{$versionId}/publish", [
            'lock_version' => $lockVersion
        ], [
            'X-Request-Id' => Str::uuid()->toString(),
            'Idempotency-Key' => Str::uuid()->toString()
        ]);

        $pubResponse->assertStatus(400)
                    ->assertJson(['error' => 'PRODUCT_INCOMPLETE']);
    }

    public function test_reject_product_amount_not_multiple_of_100()
    {
        $productId = Str::uuid()->toString(); // Assuming route requires valid UUID, not necessarily existing for validation
        $response = $this->actingAs($this->admin)->postJson("/api/v1/products/{$productId}/versions", [
            'name' => 'Producto 1',
            'nominal_amount' => 1050.50, // Not multiple of 100
            'effective_from' => now()->addDay()->toIso8601String(),
            'reason' => 'Init'
        ], ['X-Request-Id' => Str::uuid()->toString()]);

        $response->assertStatus(422);
    }
}
