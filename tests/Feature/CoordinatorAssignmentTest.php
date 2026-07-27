<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use App\Models\CoordinatorDistributorAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoordinatorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_gerente_general_puede_crear_asignacion_valida(): void
    {
        $branch = Branch::factory()->create(['city' => 'Torreón', 'is_headquarters' => true]);

        $role = Role::firstOrCreate(['code' => 'GENERAL_MANAGER'], ['name' => 'Gerente general', 'scope' => 'GLOBAL']);
        $user = User::factory()->create([
            'role_id'   => $role->id, 
            'branch_id' => null 
        ]);

        $coordinator = User::factory()->create([
            'role_id'   => Role::firstOrCreate(['code' => 'COORDINATOR'], ['name' => 'Coordinador', 'scope' => 'BRANCH'])->id, 
            'branch_id' => $branch->id
        ]);
        
        $distributor = User::factory()->create([
            'role_id'   => Role::firstOrCreate(['code' => 'DISTRIBUTOR'], ['name' => 'Distribuidora', 'scope' => 'BRANCH'])->id, 
            'branch_id' => $branch->id
        ]);

        Sanctum::actingAs($user);
        $user->load('role'); // <-- Asegura que la relación role esté cargada para la Policy

        $response = $this->postJson('/api/m02/assignments', [
            'distributor_public_id' => $distributor->public_id,
            'coordinator_public_id' => $coordinator->public_id,
            'branch_public_id'      => $branch->public_id,
            'starts_at'             => '2026-08-01 10:00:00',
            'reason'                => 'Prueba automatizada O14'
        ]);

        $response->assertStatus(201)
                 ->assertJsonFragment(['message' => 'Asignación creada correctamente']);
    }

    public function test_rechaza_asignacion_si_coordinador_pertenece_a_otra_sucursal(): void
    {
        $branchA = Branch::factory()->create(['name' => 'Sucursal Torreón']);
        $branchB = Branch::factory()->create(['name' => 'Sucursal Externa']);

        $roleGG = Role::firstOrCreate(['code' => 'GENERAL_MANAGER'], ['name' => 'Gerente general', 'scope' => 'GLOBAL']);
        $user = User::factory()->create([
            'role_id'   => $roleGG->id, 
            'branch_id' => null
        ]);

        $roleCoord = Role::firstOrCreate(['code' => 'COORDINATOR'], ['name' => 'Coordinador', 'scope' => 'BRANCH']);
        $roleDist = Role::firstOrCreate(['code' => 'DISTRIBUTOR'], ['name' => 'Distribuidora', 'scope' => 'BRANCH']);

        $coordinator = User::factory()->create(['role_id' => $roleCoord->id, 'branch_id' => $branchB->id]);
        $distributor = User::factory()->create(['role_id' => $roleDist->id, 'branch_id' => $branchA->id]);

        Sanctum::actingAs($user);
        $user->load('role'); // <-- Asegura que la relación role esté cargada para la Policy

        $response = $this->postJson('/api/m02/assignments', [
            'distributor_public_id' => $distributor->public_id,
            'coordinator_public_id' => $coordinator->public_id,
            'branch_public_id'      => $branchA->public_id,
            'starts_at'             => '2026-08-01 10:00:00',
            'reason'                => 'Prueba de error de sucursal'
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'COORDINATOR_BRANCH_MISMATCH');
    }
}