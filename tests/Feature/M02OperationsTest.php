<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M02OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_consulta_global_de_sucursales_por_gerente_general(): void
    {
        Branch::factory()->create(['city' => 'Torreón', 'is_headquarters' => true]);
        Branch::factory()->create(['city' => 'Gómez Palacio', 'is_headquarters' => false]);

        $roleGG = Role::firstOrCreate(['code' => 'GENERAL_MANAGER'], ['name' => 'Gerente general', 'scope' => 'GLOBAL']);
        $user = User::factory()->create(['role_id' => $roleGG->id, 'branch_id' => null]);

        Sanctum::actingAs($user);
        $user->load('role');

        $response = $this->getJson('/api/m02/branches');

        $response->assertStatus(200);
    }

    public function test_consulta_de_roles_del_sistema(): void
    {
        $roleGG = Role::firstOrCreate(['code' => 'GENERAL_MANAGER'], ['name' => 'Gerente general', 'scope' => 'GLOBAL']);
        $user = User::factory()->create(['role_id' => $roleGG->id, 'branch_id' => null]);

        Sanctum::actingAs($user);
        $user->load('role');

        $response = $this->getJson('/api/m02/roles');

        $response->assertStatus(200);
    }

    public function test_consulta_de_scopes(): void
    {
        $roleGG = Role::firstOrCreate(['code' => 'GENERAL_MANAGER'], ['name' => 'Gerente general', 'scope' => 'GLOBAL']);
        $user = User::factory()->create(['role_id' => $roleGG->id, 'branch_id' => null]);

        Sanctum::actingAs($user);
        $user->load('role');

        $response = $this->getJson('/api/m02/scopes');

        $response->assertStatus(200);
    }
}
