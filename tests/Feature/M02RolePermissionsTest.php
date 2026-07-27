<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use App\Modules\Access\Infrastructure\Persistence\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M02RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerente_general_puede_actualizar_permisos_e_invalidar_sesiones(): void
    {
        $roleGG = Role::create([
            'code' => RoleCode::GENERAL_MANAGER,
            'name' => 'Gerente General',
            'scope' => 'GLOBAL',
            'is_active' => true
        ]);

        $roleTarget = Role::create([
            'code' => RoleCode::COORDINATOR,
            'name' => 'Coordinador',
            'scope' => 'BRANCH',
            'is_active' => true
        ]);

        $permissionCase = PermissionCode::cases()[0] ?? null;
        $permCode = $permissionCase ? $permissionCase->value : 'organization.branches.read_global';

        $permission = Permission::create([
            'code' => $permCode,
            'name' => 'Permiso de Prueba'
        ]);

        $actorGG = User::factory()->create([
            'role_id'   => $roleGG->id,
            'branch_id' => null,
            'state'     => 'ACTIVE'
        ]);

        $affectedUser = User::factory()->create([
            'role_id'         => $roleTarget->id,
            'context_version' => 1,
            'state'           => 'ACTIVE'
        ]);

        Sanctum::actingAs($actorGG);

        $response = $this->putJson("/api/m02/roles/{$roleTarget->id}/permissions", [
            'permissions' => [$permCode],
            'reason'      => 'Actualización de permisos por auditoría'
        ]);

        $response->assertStatus(200);

        $this->assertEquals(2, $affectedUser->fresh()->context_version);
    }

    public function test_gerente_de_sucursal_no_puede_modificar_permisos(): void
    {
        // Usamos un caso válido del Enum RoleCode (ej. COORDINATOR) para el rol local
        $roleBM = Role::create([
            'code' => RoleCode::COORDINATOR,
            'name' => 'Gerente de Sucursal',
            'scope' => 'BRANCH',
            'is_active' => true
        ]);

        $roleTarget = Role::create([
            'code' => RoleCode::GENERAL_MANAGER,
            'name' => 'General',
            'scope' => 'GLOBAL',
            'is_active' => true
        ]);

        $actorBM = User::factory()->create([
            'role_id' => $roleBM->id,
            'state'   => 'ACTIVE'
        ]);

        Sanctum::actingAs($actorBM);

        $response = $this->putJson("/api/m02/roles/{$roleTarget->id}/permissions", [
            'permissions' => [],
            'reason'      => 'Intento de escalamiento'
        ]);

        $response->assertStatus(403)
                 ->assertJsonPath('error.code', 'ORGANIZATION_SCOPE_DENIED');
    }
}