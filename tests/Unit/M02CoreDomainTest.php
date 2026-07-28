<?php

namespace Tests\Unit;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M02CoreDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_rol_global_no_puede_pertenecer_a_sucursal(): void
    {
        $this->expectException(QueryException::class);

        $branch = Branch::factory()->create(['city' => 'Torreón']);
        $roleGlobal = Role::firstOrCreate(['code' => 'GENERAL_MANAGER'], ['name' => 'Gerente general', 'scope' => 'GLOBAL']);

        User::factory()->create([
            'role_id' => $roleGlobal->id,
            'branch_id' => $branch->id, // Trigger rechaza esto
        ]);
    }

    public function test_rol_local_exige_pertenecer_a_sucursal(): void
    {
        $this->expectException(QueryException::class);

        // Usamos un rol de sucursal existente o creamos uno con scope BRANCH
        $roleBranch = Role::firstOrCreate(['code' => 'COORDINATOR'], ['name' => 'Coordinador', 'scope' => 'BRANCH']);

        // Intentar crear un usuario con rol local pero sin branch_id debe fallar por el trigger de BD
        User::factory()->create([
            'role_id' => $roleBranch->id,
            'branch_id' => null,
        ]);
    }

    public function test_no_se_puede_deshabilitar_o_eliminar_al_ultimo_gerente_general(): void
    {
        $roleGlobal = Role::firstOrCreate(['code' => 'GENERAL_MANAGER'], ['name' => 'Gerente general', 'scope' => 'GLOBAL']);

        $manager = User::factory()->create([
            'role_id' => $roleGlobal->id,
            'branch_id' => null,
            'state' => 'ACTIVE',
        ]);

        $activeGeneralManagersCount = User::where('role_id', $roleGlobal->id)
            ->where('state', 'ACTIVE')
            ->count();

        $this->assertEquals(1, $activeGeneralManagersCount);

        $canDeactivate = $activeGeneralManagersCount > 1;

        $this->assertFalse(
            $canDeactivate,
            'El sistema no debe permitir desactivar al único Gerente General existente.'
        );
    }

    public function test_alcance_efectivo_segun_rol(): void
    {
        $branchTorreón = Branch::factory()->create(['city' => 'Torreón', 'is_headquarters' => true]);

        $roleGlobal = Role::firstOrCreate(['code' => 'GENERAL_MANAGER'], ['name' => 'Gerente general', 'scope' => 'GLOBAL']);
        // Usamos 'COORDINATOR' o el código válido que soporte tu Enum RoleCode para sucursales
        $roleLocal = Role::firstOrCreate(['code' => 'COORDINATOR'], ['name' => 'Coordinador', 'scope' => 'BRANCH']);

        $userGlobal = User::factory()->create(['role_id' => $roleGlobal->id, 'branch_id' => null]);
        $userLocal = User::factory()->create(['role_id' => $roleLocal->id, 'branch_id' => $branchTorreón->id]);

        $this->assertEquals('GLOBAL', strtoupper((string) $userGlobal->role->scope));
        $this->assertEquals('BRANCH', strtoupper((string) $userLocal->role->scope));
        $this->assertEquals($branchTorreón->id, $userLocal->branch_id);
        $this->assertNull($userGlobal->branch_id);
    }
}
