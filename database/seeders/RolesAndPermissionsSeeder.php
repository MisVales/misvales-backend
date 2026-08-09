<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissions();
        $this->seedRoles();
    }

    private function seedPermissions(): void
    {
        $permissions = [
            ['module' => 'users', 'action' => 'view', 'code' => 'users.view', 'description' => 'Ver lista y detalle de usuarios'],
            ['module' => 'users', 'action' => 'create', 'code' => 'users.create', 'description' => 'Crear e invitar nuevos usuarios'],
            ['module' => 'users', 'action' => 'update', 'code' => 'users.update', 'description' => 'Modificar información de usuarios'],
            ['module' => 'users', 'action' => 'manage_state', 'code' => 'users.manage_state', 'description' => 'Bloquear, desbloquear y deshabilitar usuarios'],

            // Roles Module
            ['module' => 'roles', 'action' => 'view', 'code' => 'roles.view', 'description' => 'Ver roles y permisos del sistema'],
            ['module' => 'roles', 'action' => 'assign', 'code' => 'roles.assign', 'description' => 'Asignar roles, sucursales y alcances operativos'],
            ['module' => 'roles', 'action' => 'manage_permissions', 'code' => 'roles.manage_permissions', 'description' => 'Modificar permisos de un rol'],
            ['module' => 'sessions', 'action' => 'view_global', 'code' => 'sessions.view_global', 'description' => 'Ver sesiones de otros usuarios'],
            ['module' => 'sessions', 'action' => 'revoke_global', 'code' => 'sessions.revoke_global', 'description' => 'Revocar sesiones de otros usuarios'],

            // Branches Module
            ['module' => 'branches', 'action' => 'view', 'code' => 'branches.view', 'description' => 'Consultar sucursales registradas'],
            ['module' => 'branches', 'action' => 'create', 'code' => 'branches.create', 'description' => 'Crear nuevas sucursales'],
            ['module' => 'branches', 'action' => 'update', 'code' => 'branches.update', 'description' => 'Modificar datos generales de sucursales'],
            ['module' => 'branches', 'action' => 'manage_state', 'code' => 'branches.manage_state', 'description' => 'Activar o desactivar sucursales'],

            // Assignments Module
            ['module' => 'assignments', 'action' => 'manage', 'code' => 'assignments.manage', 'description' => 'Asignar distribuidoras a coordinadores y reasignar'],

            // Configurations and catalogs module
            ['module' => 'catalogs', 'action' => 'view_published', 'code' => 'catalogs.view_published', 'description' => 'Consultar configuraciones y catálogos publicados'],
            ['module' => 'catalogs', 'action' => 'view_history', 'code' => 'catalogs.view_history', 'description' => 'Consultar versiones históricas de configuraciones y catálogos'],
            ['module' => 'catalogs', 'action' => 'manage', 'code' => 'catalogs.manage', 'description' => 'Crear, modificar y publicar configuraciones y catálogos'],

            // Audit Module
            ['module' => 'audit', 'action' => 'view', 'code' => 'audit.view', 'description' => 'Ver auditoría y eventos de seguridad'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::updateOrCreate(['code' => $permissionData['code']], $permissionData);
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
            $role = Role::updateOrCreate(['code' => $roleData['code']], $roleData);
            $role->permissions()->detach(); // Reset permissions for clean seed

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
                    ? ['users.view', 'roles.view', 'roles.assign', 'branches.view', 'assignments.manage', 'distributor_applications.view', 'distributor_applications.create', 'distributor_applications.update', 'distributor_applications.submit']
                    : ['users.view', 'roles.view', 'branches.view', 'catalogs.view_published', 'catalogs.view_history', 'distributor_applications.view'];
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

    private function assignPerms(Role $role, array $codes)
    {
        $permissions = Permission::whereIn('code', $codes)->get();
        $syncData = [];
        foreach ($permissions as $perm) {
            $syncData[$perm->id] = ['id' => Str::uuid()->toString(), 'granted_at' => now()];
        }
        $role->permissions()->syncWithoutDetaching($syncData);
    }
}
