<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ConfiguracionDetalleValorVigenteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_detail_includes_its_current_published_value(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        UserRoleScope::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', 'general_manager')->value('id'),
            'scope_type' => 'GLOBAL',
            'assigned_by_user_id' => $user->id,
        ]);
        $definition = ConfigurationDefinition::query()->create([
            'key' => 'DETAIL_CURRENT_VALUE_'.Str::upper(Str::random(8)),
            'name' => 'Valor vigente del detalle',
            'value_type' => 'DECIMAL',
            'status' => 'ACTIVE',
            'created_by' => $user->id,
        ]);
        ConfigurationVersion::query()->create([
            'configuration_definition_id' => $definition->id,
            'version' => 1,
            'value' => '500.0000',
            'status' => 'PUBLISHED',
            'effective_from' => now()->subMinute(),
            'reason' => 'Configuración vigente de prueba',
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/configurations/{$definition->key}")
            ->assertSuccessful()
            ->assertJsonPath('data.versions.0.value', '500.0000')
            ->assertJsonCount(1, 'data.versions');
    }
}
