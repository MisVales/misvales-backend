<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class RolesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->roles() as $role) {
                Role::query()->updateOrCreate(
                    ['code' => $role['code']],
                    $role,
                );
            }
        });
    }

    /** @return list<array<string, mixed>> */
    private function roles(): array
    {
        return [
            ['code' => 'general_manager', 'name' => 'Gerente general', 'description' => 'Responsable global del sistema', 'default_scope' => 'GLOBAL', 'is_system' => true, 'is_active' => true],
            ['code' => 'branch_manager', 'name' => 'Gerente de sucursal', 'description' => 'Responsable operativo de una sucursal', 'default_scope' => 'BRANCH', 'is_system' => true, 'is_active' => true],
            ['code' => 'coordinator', 'name' => 'Coordinador', 'description' => 'Coordinador de registros y distribuidoras asignadas', 'default_scope' => 'ASSIGNED', 'is_system' => true, 'is_active' => true],
            ['code' => 'verifier', 'name' => 'Verificador', 'description' => 'Personal de verificación de solicitudes asignadas', 'default_scope' => 'ASSIGNED', 'is_system' => true, 'is_active' => true],
            ['code' => 'admin', 'name' => 'Administrador', 'description' => 'Consulta global de solo lectura', 'default_scope' => 'GLOBAL', 'is_system' => true, 'is_active' => true],
            ['code' => 'distributor', 'name' => 'Distribuidora', 'description' => 'Ámbito propio de la distribuidora', 'default_scope' => 'SELF', 'is_system' => true, 'is_active' => true],
            ['code' => 'cashier', 'name' => 'Cajera', 'description' => 'Operación de caja de una sucursal', 'default_scope' => 'BRANCH', 'is_system' => true, 'is_active' => true],
        ];
    }
}
