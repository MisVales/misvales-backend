<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedPermissions();
        $this->seedRoles();
    }

    private function seedPermissions(): void
    {
        $permissions = [
            // Users Module
            ['module' => 'users', 'action' => 'view', 'code' => 'users.view', 'description' => 'Ver lista y detalle de usuarios'],
            ['module' => 'users', 'action' => 'create', 'code' => 'users.create', 'description' => 'Crear e invitar nuevos usuarios'],
            ['module' => 'users', 'action' => 'update', 'code' => 'users.update', 'description' => 'Modificar información de usuarios'],
            ['module' => 'users', 'action' => 'manage_state', 'code' => 'users.manage_state', 'description' => 'Bloquear, desbloquear y deshabilitar usuarios'],

            // Roles Module
            ['module' => 'roles', 'action' => 'view', 'code' => 'roles.view', 'description' => 'Ver roles y permisos del sistema'],
            ['module' => 'roles', 'action' => 'assign', 'code' => 'roles.assign', 'description' => 'Asignar roles, sucursales y alcances operativos'],
            ['module' => 'roles', 'action' => 'manage_permissions', 'code' => 'roles.manage_permissions', 'description' => 'Modificar permisos de un rol'],

            // Sessions Module
            ['module' => 'sessions', 'action' => 'view_global', 'code' => 'sessions.view_global', 'description' => 'Ver sesiones de otros usuarios'],
            ['module' => 'sessions', 'action' => 'revoke_global', 'code' => 'sessions.revoke_global', 'description' => 'Revocar sesiones de otros usuarios'],

            // Organization Module
            ['module' => 'organization', 'action' => 'view_branches', 'code' => 'branches.view', 'description' => 'Consultar sucursales dentro del alcance efectivo'],
            ['module' => 'organization', 'action' => 'create_branches', 'code' => 'branches.create', 'description' => 'Crear sucursales'],
            ['module' => 'organization', 'action' => 'update_branches', 'code' => 'branches.update', 'description' => 'Modificar sucursales'],
            ['module' => 'organization', 'action' => 'activate_branches', 'code' => 'branches.activate', 'description' => 'Activar sucursales'],
            ['module' => 'organization', 'action' => 'deactivate_branches', 'code' => 'branches.deactivate', 'description' => 'Desactivar sucursales'],

            // Distributor Applications Module
            ['module' => 'distributor_applications', 'action' => 'view', 'code' => 'distributor_applications.view', 'description' => 'Consultar solicitudes de distribuidoras dentro del alcance autorizado'],
            ['module' => 'distributor_applications', 'action' => 'create', 'code' => 'distributor_applications.create', 'description' => 'Crear solicitudes de distribuidoras'],
            ['module' => 'distributor_applications', 'action' => 'update', 'code' => 'distributor_applications.update', 'description' => 'Modificar solicitudes de distribuidoras en borrador'],
            ['module' => 'distributor_applications', 'action' => 'submit', 'code' => 'distributor_applications.submit', 'description' => 'Enviar solicitudes de distribuidoras a revisión'],
            ['module' => 'distributor_applications', 'action' => 'view_sensitive', 'code' => 'distributor_applications.view_sensitive', 'description' => 'Consultar datos sensibles completos de solicitudes de distribuidoras'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::updateOrCreate(
                ['code' => $permissionData['code']],
                $permissionData
            );
        }
    }

    private function seedRoles(): void
    {
        $roles = [
            ['code' => 'general_manager', 'name' => 'Gerente general', 'description' => 'Administrador global del sistema', 'default_scope' => 'GLOBAL'],
            ['code' => 'branch_manager', 'name' => 'Gerente de sucursal', 'description' => 'Administrador a nivel sucursal', 'default_scope' => 'BRANCH'],
            ['code' => 'coordinator', 'name' => 'Coordinador', 'description' => 'Coordinador operativo', 'default_scope' => 'BRANCH'],
            ['code' => 'verifier', 'name' => 'Verificador', 'description' => 'Personal de verificación', 'default_scope' => 'BRANCH'],
            ['code' => 'admin', 'name' => 'Administrador', 'description' => 'Administrador de sistema', 'default_scope' => 'GLOBAL'],
            ['code' => 'distributor', 'name' => 'Distribuidora', 'description' => 'Distribuidora de vales', 'default_scope' => 'ASSIGNED'],
            ['code' => 'cashier', 'name' => 'Cajera', 'description' => 'Cajera de sucursal', 'default_scope' => 'BRANCH'],
        ];

        foreach ($roles as $roleData) {
            $role = Role::updateOrCreate(
                ['code' => $roleData['code']],
                $roleData
            );

            // Al Gerente General le damos todos los permisos por defecto
            if ($roleData['code'] === 'general_manager') {
                $permissions = Permission::all();
                $syncData = [];
                foreach ($permissions as $perm) {
                    $syncData[$perm->id] = [
                        'id' => Str::uuid()->toString(),
                        'granted_at' => now(),
                    ];
                }
                $role->permissions()->sync($syncData);
            }

            if (in_array($roleData['code'], ['admin', 'branch_manager'], true)) {
                $permissionCodes = $roleData['code'] === 'branch_manager'
                    ? ['branches.view', 'roles.assign', 'distributor_applications.view', 'distributor_applications.create', 'distributor_applications.update', 'distributor_applications.submit']
                    : ['branches.view', 'distributor_applications.view'];
                $permissions = Permission::query()->whereIn('code', $permissionCodes)->get();
                $syncData = [];

                foreach ($permissions as $permission) {
                    $syncData[$permission->id] = [
                        'id' => Str::uuid()->toString(),
                        'granted_at' => now(),
                    ];
                }

                $role->permissions()->syncWithoutDetaching($syncData);
            }

            if ($roleData['code'] === 'coordinator') {
                $permissions = Permission::query()->whereIn('code', [
                    'distributor_applications.view',
                    'distributor_applications.create',
                    'distributor_applications.update',
                    'distributor_applications.submit',
                ])->get();
                $syncData = [];

                foreach ($permissions as $permission) {
                    $syncData[$permission->id] = [
                        'id' => Str::uuid()->toString(),
                        'granted_at' => now(),
                    ];
                }

                $role->permissions()->syncWithoutDetaching($syncData);
            }
        }
    }
}
