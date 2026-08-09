<?php

namespace Tests\Feature;

use App\Models\ConfigurationDefinition;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\TestCase;

class ConfiguracionFeatureTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_crear_configuracion_con_version_draft()
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $this->assignGeneralManager($user);
        // Simular que completó MFA para pasar el middleware
        $this->actingAs($user);

        $payload = [
            'key' => 'MAX_LOAN_AMOUNT',
            'name' => 'Monto Máximo de Préstamo',
            'value_type' => 'DECIMAL',
            'value' => 50000.00,
            'reason' => 'Configuración inicial',
            'effective_from' => now()->addDay()->format('Y-m-d H:i:s'),
        ];

        $response = $this->postJson('/api/v1/configurations', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('configuration_definitions', ['key' => 'MAX_LOAN_AMOUNT']);
        $this->assertDatabaseHas('configuration_versions', [
            'version' => 1,
            'status' => 'DRAFT',
            'value' => json_encode('50000.0000'),
        ]);
    }

    public function test_publicar_version_cierra_version_previa()
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $this->assignGeneralManager($user);

        $definition = ConfigurationDefinition::create([
            'key' => 'TAX_RATE',
            'name' => 'Tasa de Impuesto',
            'value_type' => 'DECIMAL',
            'status' => 'ACTIVE',
            'created_by' => $user->id,
        ]);

        // Versión 1 actualmente publicada
        $v1 = $definition->versions()->create([
            'version' => 1,
            'value' => 0.16,
            'status' => 'PUBLISHED',
            'effective_from' => now()->subDays(10),
            'reason' => 'Initial',
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now()->subDays(10),
        ]);

        // Versión 2 en borrador
        $v2 = $definition->versions()->create([
            'version' => 2,
            'value' => 0.18,
            'status' => 'DRAFT',
            'effective_from' => now()->addDays(5),
            'reason' => 'Increase tax',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        $response = $this->postJson("/api/v1/configuration-versions/{$v2->id}/publish", [
            'reason' => 'Increase tax',
            'lock_version' => 0,
        ]);
        $response->assertStatus(200);

        // Verificar que v2 ahora es PUBLISHED
        $this->assertEquals('PUBLISHED', $v2->fresh()->status->value);

        // Verificar que v1 ahora tiene un effective_to que es exactamente el effective_from de v2
        $this->assertEquals($v2->fresh()->effective_from->toDateTimeString(), $v1->fresh()->effective_to->toDateTimeString());
    }

    private function assignGeneralManager(User $user): void
    {
        UserRoleScope::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', 'general_manager')->value('id'),
            'scope_type' => 'GLOBAL',
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
        ]);
    }
}
