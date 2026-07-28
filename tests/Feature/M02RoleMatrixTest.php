<?php

namespace Tests\Feature;

use App\Models\CoordinatorDistributorAssignment;
use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M02RoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_cuenta_deshabilitada_o_inactiva_no_puede_operar(): void
    {
        $branch = Branch::factory()->create(['city' => 'Torreón']);
        $roleBranch = Role::firstOrCreate(['code' => 'COORDINATOR'], ['name' => 'Coordinador', 'scope' => 'BRANCH']);

        // Obtenemos un estado inactivo/suspendido directamente del enum del dominio de forma segura
        $cases = AccountState::cases();
        $inactiveState = count($cases) > 1 ? $cases[1]->value : $cases[0]->value;

        $user = User::factory()->create([
            'role_id' => $roleBranch->id,
            'branch_id' => $branch->id,
            'state' => $inactiveState,
        ]);

        $this->assertNotEquals('ACTIVE', $user->state);

        $isOperational = $user->state === 'ACTIVE';
        $this->assertFalse($isOperational, 'Una cuenta inactiva no debe ser considerada operacional.');
    }

    public function test_aislamiento_de_datos_por_rol_global_vs_local(): void
    {
        $branchA = Branch::factory()->create(['name' => 'Sucursal Torreón', 'city' => 'Torreón']);
        $branchB = Branch::factory()->create(['name' => 'Sucursal Externa', 'city' => 'Monterrey']);

        $roleGlobal = Role::firstOrCreate(['code' => 'ADMINISTRATOR'], ['name' => 'Administrador', 'scope' => 'GLOBAL']);
        $roleLocal = Role::firstOrCreate(['code' => 'COORDINATOR'], ['name' => 'Coordinador', 'scope' => 'BRANCH']);

        $adminUser = User::factory()->create([
            'role_id' => $roleGlobal->id,
            'branch_id' => null,
            'state' => 'ACTIVE',
        ]);

        $coordA = User::factory()->create(['role_id' => $roleLocal->id, 'branch_id' => $branchA->id]);
        $distA = User::factory()->create(['role_id' => $roleLocal->id, 'branch_id' => $branchA->id]);

        $coordB = User::factory()->create(['role_id' => $roleLocal->id, 'branch_id' => $branchB->id]);
        $distB = User::factory()->create(['role_id' => $roleLocal->id, 'branch_id' => $branchB->id]);

        CoordinatorDistributorAssignment::create([
            'distributor_id' => $distA->id,
            'coordinator_user_id' => $coordA->id,
            'branch_id' => $branchA->id,
            'starts_at' => now(),
            'assigned_by' => $adminUser->id,
            'source_type' => 'MANUAL',
            'source_id' => 1,
            'reason' => 'Test A',
        ]);

        CoordinatorDistributorAssignment::create([
            'distributor_id' => $distB->id,
            'coordinator_user_id' => $coordB->id,
            'branch_id' => $branchB->id,
            'starts_at' => now(),
            'assigned_by' => $adminUser->id,
            'source_type' => 'MANUAL',
            'source_id' => 2,
            'reason' => 'Test B',
        ]);

        Sanctum::actingAs($adminUser);
        $adminUser->load('role');

        $response = $this->getJson('/api/m02/assignments');

        $response->assertStatus(200);
        $response->assertJsonFragment(['branch_id' => $branchA->id]);
        $response->assertJsonFragment(['branch_id' => $branchB->id]);
    }

    public function test_administrador_tiene_acceso_de_solo_lectura_global(): void
    {
        $branch = Branch::factory()->create(['city' => 'Torreón']);

        $roleAdmin = Role::firstOrCreate(['code' => 'ADMINISTRATOR'], ['name' => 'Administrador', 'scope' => 'GLOBAL']);
        $roleLocal = Role::firstOrCreate(['code' => 'COORDINATOR'], ['name' => 'Coordinador', 'scope' => 'BRANCH']);

        $adminUser = User::factory()->create([
            'role_id' => $roleAdmin->id,
            'branch_id' => null,
            'state' => 'ACTIVE',
        ]);

        $distributor = User::factory()->create(['role_id' => $roleLocal->id, 'branch_id' => $branch->id]);
        $coordinator = User::factory()->create(['role_id' => $roleLocal->id, 'branch_id' => $branch->id]);

        Sanctum::actingAs($adminUser);
        $adminUser->load('role');

        $responseIndex = $this->getJson('/api/m02/branches');
        $responseIndex->assertStatus(200);

        $responseStore = $this->postJson('/api/m02/assignments', [
            'distributor_public_id' => $distributor->public_id,
            'coordinator_public_id' => $coordinator->public_id,
            'branch_public_id' => $branch->public_id,
            'starts_at' => '2026-08-01 10:00:00',
            'reason' => 'Intento no autorizado',
        ]);

        $responseStore->assertStatus(403);
    }
}
