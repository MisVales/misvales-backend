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

            // Distributors Module
            ['module' => 'distributors', 'action' => 'view_any', 'code' => 'distributors.view_any', 'description' => 'Listar distribuidoras dentro del alcance autorizado'],
            ['module' => 'distributors', 'action' => 'view', 'code' => 'distributors.view', 'description' => 'Consultar una distribuidora'],
            ['module' => 'distributors', 'action' => 'activate', 'code' => 'distributors.activate', 'description' => 'Materializar una solicitud autorizada'],
            ['module' => 'distributors', 'action' => 'assign_category', 'code' => 'distributors.assign_category', 'description' => 'Asignar una categoría publicada'],
            ['module' => 'distributors', 'action' => 'view_category_history', 'code' => 'distributors.view_category_history', 'description' => 'Consultar el historial de categorías'],
            ['module' => 'distributors', 'action' => 'resend_activation', 'code' => 'distributors.resend_activation', 'description' => 'Reenviar una invitación de activación'],
            ['module' => 'distributors', 'action' => 'view_initial_credit', 'code' => 'distributors.view_initial_credit', 'description' => 'Consultar la línea inicial autorizada'],

            // Clients Module
            ['module' => 'clients', 'action' => 'view', 'code' => 'clients.view', 'description' => 'Consultar clientes finales dentro del alcance autorizado'],
            ['module' => 'clients', 'action' => 'view_sensitive', 'code' => 'clients.view_sensitive', 'description' => 'Consultar datos sensibles completos de clientes finales'],
            ['module' => 'clients', 'action' => 'create', 'code' => 'clients.create', 'description' => 'Registrar clientes finales propios'],
            ['module' => 'clients', 'action' => 'view_assignment_history', 'code' => 'clients.view_assignment_history', 'description' => 'Consultar historial de asignaciones del cliente'],
            ['module' => 'clients', 'action' => 'view_bank_accounts', 'code' => 'clients.view_bank_accounts', 'description' => 'Consultar cuentas bancarias enmascaradas del cliente'],
            ['module' => 'clients', 'action' => 'manage_bank_accounts', 'code' => 'clients.manage_bank_accounts', 'description' => 'Administrar cuentas bancarias del cliente'],
            ['module' => 'clients', 'action' => 'view_portfolio', 'code' => 'clients.view_portfolio', 'description' => 'Consultar cartera informativa del cliente'],
            ['module' => 'clients', 'action' => 'manage_portfolio', 'code' => 'clients.manage_portfolio', 'description' => 'Administrar cartera informativa propia'],

            // Audit Module
            ['module' => 'audit', 'action' => 'view', 'code' => 'audit.view', 'description' => 'Ver auditoría y eventos de seguridad'],

            // Distributor Applications Module
            ['module' => 'distributor_applications', 'action' => 'view', 'code' => 'distributor_applications.view', 'description' => 'Ver solicitudes de distribuidoras'],
            ['module' => 'distributor_applications', 'action' => 'create', 'code' => 'distributor_applications.create', 'description' => 'Crear solicitud de distribuidora'],
            ['module' => 'distributor_applications', 'action' => 'update', 'code' => 'distributor_applications.update', 'description' => 'Editar solicitud de distribuidora en borrador'],
            ['module' => 'distributor_applications', 'action' => 'submit', 'code' => 'distributor_applications.submit', 'description' => 'Enviar a revisión la solicitud de distribuidora'],

            // Credit Lines Module
            ['module' => 'credit_lines', 'action' => 'view_own', 'code' => 'credit_lines.view_own', 'description' => 'Consultar su propia línea'],
            ['module' => 'credit_lines', 'action' => 'view_assigned', 'code' => 'credit_lines.view_assigned', 'description' => 'Consultar líneas de sus distribuidoras'],
            ['module' => 'credit_lines', 'action' => 'view_branch', 'code' => 'credit_lines.view_branch', 'description' => 'Consultar líneas de su sucursal'],
            ['module' => 'credit_lines', 'action' => 'view_global', 'code' => 'credit_lines.view_global', 'description' => 'Consultar líneas globalmente'],
            ['module' => 'credit_lines', 'action' => 'view_movements_own', 'code' => 'credit_line_movements.view_own', 'description' => 'Consultar movimientos propios'],
            ['module' => 'credit_lines', 'action' => 'view_movements_assigned', 'code' => 'credit_line_movements.view_assigned', 'description' => 'Consultar movimientos asignados'],
            ['module' => 'credit_lines', 'action' => 'view_movements_branch', 'code' => 'credit_line_movements.view_branch', 'description' => 'Consultar movimientos de sucursal'],
            ['module' => 'credit_lines', 'action' => 'view_movements_global', 'code' => 'credit_line_movements.view_global', 'description' => 'Consultar movimientos globalmente'],
            ['module' => 'credit_lines', 'action' => 'create_increase_own', 'code' => 'credit_increase_requests.create_own', 'description' => 'Crear solicitudes propias'],
            ['module' => 'credit_lines', 'action' => 'view_requests_own', 'code' => 'credit_increase_requests.view_own', 'description' => 'Consultar solicitudes propias'],
            ['module' => 'credit_lines', 'action' => 'view_requests_assigned', 'code' => 'credit_increase_requests.view_assigned', 'description' => 'Consultar solicitudes asignadas'],
            ['module' => 'credit_lines', 'action' => 'view_requests_branch', 'code' => 'credit_increase_requests.view_branch', 'description' => 'Consultar solicitudes de sucursal'],
            ['module' => 'credit_lines', 'action' => 'view_requests_global', 'code' => 'credit_increase_requests.view_global', 'description' => 'Consultar solicitudes globalmente'],
            ['module' => 'credit_lines', 'action' => 'preauthorize_assigned', 'code' => 'credit_increase_requests.preauthorize_assigned', 'description' => 'Preautorizar solicitudes asignadas'],
            ['module' => 'credit_lines', 'action' => 'reject_assigned', 'code' => 'credit_increase_requests.reject_assigned', 'description' => 'Rechazar operativamente solicitudes asignadas'],
            ['module' => 'credit_lines', 'action' => 'decide_branch', 'code' => 'credit_increase_requests.decide_branch', 'description' => 'Decidir solicitudes de sucursal'],
            ['module' => 'credit_lines', 'action' => 'decide_global', 'code' => 'credit_increase_requests.decide_global', 'description' => 'Decidir solicitudes globalmente'],
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

            if ($roleData['code'] === 'branch_manager') {
                $this->assignPerms($role, [
                    'distributors.view_any', 'distributors.view', 'distributors.activate',
                    'distributors.assign_category', 'distributors.view_category_history',
                    'distributors.resend_activation', 'distributors.view_initial_credit',
                    'clients.view', 'clients.view_sensitive', 'clients.view_assignment_history',
                    'clients.view_bank_accounts', 'clients.view_portfolio',
                    'credit_lines.view_branch', 'credit_line_movements.view_branch',
                    'credit_increase_requests.view_branch', 'credit_increase_requests.decide_branch',
                ]);
            }

            if ($roleData['code'] === 'admin') {
                $this->assignPerms($role, [
                    'distributors.view_any', 'distributors.view', 'distributors.view_category_history',
                    'distributors.view_initial_credit', 'clients.view', 'clients.view_assignment_history',
                    'clients.view_bank_accounts', 'clients.view_portfolio',
                    'credit_lines.view_global', 'credit_line_movements.view_global', 'credit_increase_requests.view_global',
                ]);
            }

            if ($roleData['code'] === 'coordinator') {
                $this->assignPerms($role, [
                    'distributors.view_any', 'distributors.view', 'distributors.view_category_history',
                    'distributors.view_initial_credit', 'clients.view', 'clients.view_assignment_history',
                    'clients.view_bank_accounts', 'clients.view_portfolio',
                    'credit_lines.view_assigned', 'credit_line_movements.view_assigned',
                    'credit_increase_requests.view_assigned', 'credit_increase_requests.preauthorize_assigned',
                    'credit_increase_requests.reject_assigned',
                ]);
            }

            if ($roleData['code'] === 'distributor') {
                $this->assignPerms($role, [
                    'distributors.view', 'distributors.view_category_history', 'distributors.view_initial_credit',
                    'clients.view', 'clients.create', 'clients.view_bank_accounts',
                    'clients.manage_bank_accounts', 'clients.view_portfolio', 'clients.manage_portfolio',
                    'credit_lines.view_own', 'credit_line_movements.view_own', 
                    'credit_increase_requests.create_own', 'credit_increase_requests.view_own',
                ]);
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
