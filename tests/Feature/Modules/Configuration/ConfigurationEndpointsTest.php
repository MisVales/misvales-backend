<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Configuration;

use App\Models\User;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationDefinitionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConfigurationEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $viewer;
    private string $configKey;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ejecutamos solo los seeders base necesarios para roles y permisos
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\AccessFoundationSeeder']);

        $this->configKey = ConfigurationKey::POINTS_MULTIPLIER->value;

        // Recuperamos el rol de gerente general
        $role = \App\Modules\Access\Infrastructure\Persistence\Models\Role::query()->where('code', 'GENERAL_MANAGER')->firstOrFail();

        // Creamos administrador asignándole el rol correcto
        $this->admin = clone User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => null,
            'state' => \App\Modules\Access\Domain\Accounts\AccountState::ACTIVE,
        ]);

        // AHORA que existe el administrador, corremos el seeder de configuraciones (para que le asigne la auditoría)
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\ConfigurationFoundationSeeder']);

        // Creamos un rol sin permisos de configuración
        $viewerRole = \App\Modules\Access\Infrastructure\Persistence\Models\Role::query()->where('code', 'VERIFIER')->firstOrFail();
        $branch = \App\Modules\Access\Infrastructure\Persistence\Models\Branch::query()->firstOrFail();

        $this->viewer = clone User::factory()->create([
            'role_id' => $viewerRole->id,
            'branch_id' => $branch->id,
            'state' => \App\Modules\Access\Domain\Accounts\AccountState::ACTIVE,
        ]);
    }

    public function test_can_list_current_configurations(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/configurations');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'key', 'type', 'value', 'version' => [
                                 'id', 'number', 'effective_from'
                             ]
                         ]
                     ]
                 ]);
        $this->assertGreaterThan(0, count($response->json('data')));
    }

    public function test_unauthorized_user_cannot_list_configurations(): void
    {
        // Require el permiso CONFIGURATION_VIEW_CURRENT
        $response = $this->actingAs($this->viewer)->getJson('/api/configurations');

        $response->assertStatus(403);
    }

    public function test_can_create_configuration_version_draft(): void
    {
        $payload = [
            'key' => $this->configKey,
            'value' => '2',
        ];

        // Simulamos haber ejecutado una Acción Crítica de MFA en sesión.
        $this->withSession(['critical_actions' => [\App\Modules\Access\Domain\Authorization\CriticalAction::CONFIGURATION_VERSION_CREATE->value => time()]]);

        $response = $this->actingAs($this->admin)->postJson("/api/configurations/{$this->configKey}/versions", $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'DRAFT')
                 ->assertJsonPath('data.value', '2');
    }

    public function test_cannot_create_draft_without_mfa(): void
    {
        $payload = [
            'key' => $this->configKey,
            'value' => '2',
        ];

        // No agregamos MFA en sesión.
        $response = $this->actingAs($this->admin)->postJson("/api/configurations/{$this->configKey}/versions", $payload);

        $response->assertStatus(403);
    }

    public function test_can_publish_configuration_version(): void
    {
        $this->withSession(['critical_actions' => [
            \App\Modules\Access\Domain\Authorization\CriticalAction::CONFIGURATION_VERSION_CREATE->value => time(),
            \App\Modules\Access\Domain\Authorization\CriticalAction::CONFIGURATION_VERSION_PUBLISH->value => time()
        ]]);

        // Crear borrador
        $draft = $this->actingAs($this->admin)->postJson("/api/configurations/{$this->configKey}/versions", [
            'key' => $this->configKey,
            'value' => '3',
        ])->json('data');

        $publicId = $draft['id'];

        // Publicar borrador
        $response = $this->actingAs($this->admin)->postJson("/api/configurations/{$this->configKey}/versions/{$publicId}/publish", [
            'effective_from' => now()->addMinutes(10)->toIso8601String(),
            'reason' => 'Aumento promocional del multiplicador de puntos',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'PUBLISHED');
    }
}
