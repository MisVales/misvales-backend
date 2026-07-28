<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M02UserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerente_general_consulta_directorio_global_sin_exponer_secretos(): void
    {
        $roleGlobal = Role::firstOrCreate(
            ['code' => 'GENERAL_MANAGER'],
            ['name' => 'Gerente General', 'scope' => 'GLOBAL']
        );

        $actor = User::factory()->create([
            'role_id' => $roleGlobal->id,
            'branch_id' => null,
            'state' => 'ACTIVE',
        ]);

        Sanctum::actingAs($actor);
        $actor->load('role');

        $response = $this->getJson('/api/m02/users');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'name', 'email', 'role', 'scope', 'status', 'context_version', 'created_at',
                ],
            ],
        ]);

        $response->assertJsonMissing(['password' => $actor->password]);
    }

    public function test_gerente_sucursal_solo_ve_usuarios_de_su_sucursal(): void
    {
        $branchA = Branch::factory()->create(['name' => 'Sucursal Matriz']);
        $branchB = Branch::factory()->create(['name' => 'Sucursal Foránea']);

        // Usamos un rol local con código soportado por el Enum (COORDINATOR)
        $roleLocal = Role::firstOrCreate(
            ['code' => 'COORDINATOR'],
            ['name' => 'Coordinador', 'scope' => 'BRANCH']
        );

        $actorBM = User::factory()->create([
            'role_id' => $roleLocal->id,
            'branch_id' => $branchA->id,
            'state' => 'ACTIVE',
        ]);

        User::factory()->create([
            'role_id' => $roleLocal->id,
            'branch_id' => $branchA->id,
            'state' => 'ACTIVE',
        ]);

        $targetB = User::factory()->create([
            'role_id' => $roleLocal->id,
            'branch_id' => $branchB->id,
            'state' => 'ACTIVE',
        ]);

        Sanctum::actingAs($actorBM);
        $actorBM->load('role');

        $responseIndex = $this->getJson('/api/m02/users');
        $responseIndex->assertStatus(200);
        $responseIndex->assertJsonMissing(['branch_id' => $branchB->public_id]);

        $responseShow = $this->getJson('/api/m02/users/'.$targetB->public_id);
        $responseShow->assertStatus(403)
            ->assertJsonPath('error.code', 'ORGANIZATION_SCOPE_DENIED');
    }

    public function test_perfiles_no_autorizados_son_rechazados_del_directorio_general(): void
    {
        $branch = Branch::factory()->create();

        $roleRestricted = Role::firstOrCreate(
            ['code' => 'DISTRIBUTOR'],
            ['name' => 'Distribuidora', 'scope' => 'BRANCH']
        );

        $actor = User::factory()->create([
            'role_id' => $roleRestricted->id,
            'branch_id' => $branch->id,
            'state' => 'ACTIVE',
        ]);

        Sanctum::actingAs($actor);
        $actor->load('role');

        $response = $this->getJson('/api/m02/users');

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'ORGANIZATION_SCOPE_DENIED');
    }
}
