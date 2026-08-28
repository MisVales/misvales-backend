<?php

namespace Tests\Feature;

use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\Relacion\ServicioConfiguracionRelacion;
use Carbon\CarbonImmutable;
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
        $version = ConfigurationVersion::query()
            ->where('version', 1)
            ->where('status', 'DRAFT')
            ->firstOrFail();
        self::assertSame('50000.0000', json_decode((string) $version->getRawOriginal('value'), true));
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

    public function test_valida_los_datos_bancarios_publicables(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $this->assignGeneralManager($user);
        $this->actingAs($user);

        $bank = ConfigurationDefinition::query()->create([
            'key' => 'RELATION_PAYMENT_BANK',
            'name' => 'Banco de relaciones',
            'value_type' => 'JSON',
            'status' => 'ACTIVE',
            'created_by' => $user->id,
        ]);
        $base = [
            'reason' => 'Configuración operativa para pruebas',
            'effective_from' => now()->addDay()->format('Y-m-d H:i:s'),
        ];

        $this->postJson("/api/v1/configurations/{$bank->key}/versions", [
            ...$base,
            'value' => ['name' => 'Banco', 'beneficiary' => 'MisVales', 'agreement' => 'CONV-1', 'clabe' => '1234'],
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['value.clabe']]]);

        $this->postJson("/api/v1/configurations/{$bank->key}/versions", [
            ...$base,
            'value' => ['name' => 'Banco', 'beneficiary' => 'MisVales', 'agreement' => 'CONV-1', 'clabe' => '012345678901234567'],
        ])->assertCreated();
    }

    public function test_acepta_horas_de_verificacion_en_formato_de_input_time(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $this->assignGeneralManager($user);
        $this->actingAs($user);

        foreach ([
            'VERIFICATION_START_TIME' => '03:00',
            'VERIFICATION_MAX_START_TIME' => '23:00',
        ] as $key => $value) {
            ConfigurationDefinition::query()->create([
                'key' => $key,
                'name' => $key,
                'value_type' => 'TIME',
                'status' => 'ACTIVE',
                'created_by' => $user->id,
            ]);

            $this->postJson("/api/v1/configurations/{$key}/versions", [
                'value' => $value,
                'reason' => 'Horario operativo para verificadores',
                'effective_from' => now()->addDay()->format('Y-m-d H:i:s'),
            ])->assertCreated();
        }
    }

    public function test_actualiza_directamente_y_conserva_el_historial_de_cambios(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $this->assignGeneralManager($user);
        $definition = ConfigurationDefinition::query()->create([
            'key' => 'DIRECT_UPDATE_TEST',
            'name' => 'Actualización directa',
            'value_type' => 'DECIMAL',
            'status' => 'ACTIVE',
            'created_by' => $user->id,
        ]);
        $previous = $definition->versions()->create([
            'version' => 1,
            'value' => '100.0000',
            'status' => 'PUBLISHED',
            'effective_from' => now()->subMinute(),
            'reason' => 'Valor inicial registrado',
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->putJson("/api/v1/configurations/{$definition->key}/current", [
                'value' => 250,
                'reason' => 'Ajuste directo autorizado',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PUBLISHED')
            ->assertJsonPath('data.value', '250.0000');

        self::assertSame('INACTIVE', $previous->fresh()->status->value);
        self::assertNotNull($previous->fresh()->effective_to);
        self::assertDatabaseHas('configuration_versions', [
            'configuration_definition_id' => $definition->id,
            'version' => 2,
            'status' => 'PUBLISHED',
            'reason' => 'Ajuste directo autorizado',
        ]);
    }

    public function test_el_corte_programado_se_resuelve_desde_versiones_publicadas_y_no_desde_env(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $at = CarbonImmutable::parse('2026-08-25 00:05:00', 'America/Monterrey');
        foreach ([
            'BUSINESS_TIMEZONE' => ['TIMEZONE', 'America/Monterrey'],
            'CUT_DAY_OF_MONTH' => ['INTEGER', 25],
            'CUT_TIME' => ['TIME', '00:05'],
        ] as $key => [$type, $value]) {
            $definition = ConfigurationDefinition::query()->create([
                'key' => $key,
                'name' => $key,
                'value_type' => $type,
                'status' => 'ACTIVE',
                'created_by' => $user->id,
            ]);
            ConfigurationVersion::query()->create([
                'configuration_definition_id' => $definition->id,
                'version' => 1,
                'value' => $value,
                'status' => 'PUBLISHED',
                'effective_from' => $at->subDay(),
                'reason' => 'Programación publicada',
                'created_by' => $user->id,
                'published_by' => $user->id,
                'published_at' => $at->subDay(),
            ]);
        }

        $service = app(ServicioConfiguracionRelacion::class);
        $this->assertSame('2026-08-25 00:05', $service->corteProgramado($at)?->format('Y-m-d H:i'));
        $this->assertNull($service->corteProgramado($at->addMinute()));
    }

    public function test_el_listado_expone_solo_la_version_realmente_vigente(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $this->assignGeneralManager($user);
        $definition = ConfigurationDefinition::query()->create([
            'key' => 'LIST_CURRENT_ONLY',
            'name' => 'Vigencia actual',
            'value_type' => 'INTEGER',
            'status' => 'ACTIVE',
            'created_by' => $user->id,
        ]);
        ConfigurationVersion::query()->create([
            'configuration_definition_id' => $definition->id,
            'version' => 1,
            'value' => 1,
            'status' => 'PUBLISHED',
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
            'reason' => 'Vigente',
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now()->subDay(),
        ]);
        ConfigurationVersion::query()->create([
            'configuration_definition_id' => $definition->id,
            'version' => 2,
            'value' => 2,
            'status' => 'PUBLISHED',
            'effective_from' => now()->addDay(),
            'reason' => 'Futura',
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/configurations')
            ->assertSuccessful()
            ->assertJsonPath('data.0.versions.0.value', 1)
            ->assertJsonCount(1, 'data.0.versions');

        $this->getJson("/api/v1/configurations/{$definition->key}")
            ->assertSuccessful()
            ->assertJsonPath('data.versions.0.value', 1)
            ->assertJsonCount(1, 'data.versions');
    }

    public function test_desactivar_exige_version_vigente_y_actualiza_el_bloqueo(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $this->assignGeneralManager($user);
        $definition = ConfigurationDefinition::query()->create([
            'key' => 'DEACTIVATE_TEST',
            'name' => 'Desactivación',
            'value_type' => 'INTEGER',
            'status' => 'ACTIVE',
            'created_by' => $user->id,
        ]);
        $version = ConfigurationVersion::query()->create([
            'configuration_definition_id' => $definition->id,
            'version' => 1,
            'value' => 1,
            'status' => 'PUBLISHED',
            'effective_from' => now()->subDay(),
            'reason' => 'Vigente',
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now()->subDay(),
            'lock_version' => 2,
        ]);
        $this->actingAs($user);
        $currentVersion = $version->fresh()->lock_version;

        $this->postJson("/api/v1/configuration-versions/{$version->id}/deactivate", [
            'reason' => 'Intento obsoleto',
            'lock_version' => $currentVersion + 1,
        ])->assertStatus(409)->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');

        $this->postJson("/api/v1/configuration-versions/{$version->id}/deactivate", [
            'reason' => 'Retiro autorizado',
            'lock_version' => $currentVersion,
        ])->assertSuccessful()->assertJsonPath('data.status', 'INACTIVE')->assertJsonPath('data.lock_version', $currentVersion + 1);
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
