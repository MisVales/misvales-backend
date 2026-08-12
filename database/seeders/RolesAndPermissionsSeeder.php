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
            ['module' => 'distributor_applications', 'action' => 'view_sensitive', 'code' => 'distributor_applications.view_sensitive', 'description' => 'Consultar datos sensibles de solicitudes dentro del alcance autorizado'],
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
            ['module' => 'vouchers', 'action' => 'create_own', 'code' => 'vouchers.create_own', 'description' => 'Generar vales propios'],
            ['module' => 'vouchers', 'action' => 'view_own', 'code' => 'vouchers.view_own', 'description' => 'Consultar vales propios'],
            ['module' => 'vouchers', 'action' => 'view_assigned', 'code' => 'vouchers.view_assigned', 'description' => 'Consultar vales asignados'],
            ['module' => 'vouchers', 'action' => 'view_branch', 'code' => 'vouchers.view_branch', 'description' => 'Consultar vales de sucursal'],
            ['module' => 'vouchers', 'action' => 'view_global', 'code' => 'vouchers.view_global', 'description' => 'Consultar vales globalmente'],
            ['module' => 'vouchers', 'action' => 'cash_branch', 'code' => 'vouchers.cash_branch', 'description' => 'Operar caja y feriado en sucursal'],
            ['module' => 'voucher_modifications', 'action' => 'authorize_branch', 'code' => 'voucher_modifications.authorize_branch', 'description' => 'Autorizar correcciones de sucursal'],
            ['module' => 'voucher_modifications', 'action' => 'authorize_global', 'code' => 'voucher_modifications.authorize_global', 'description' => 'Autorizar correcciones globalmente'],
            ['module' => 'relations', 'action' => 'view_own', 'code' => 'relations.view_own', 'description' => 'Consultar relaciones propias'],
            ['module' => 'relations', 'action' => 'view_branch', 'code' => 'relations.view_branch', 'description' => 'Consultar relaciones de sucursal'],
            ['module' => 'relations', 'action' => 'view_global', 'code' => 'relations.view_global', 'description' => 'Consultar relaciones globalmente'],
            ['module' => 'relations', 'action' => 'download_own', 'code' => 'relations.download_own', 'description' => 'Descargar relaciones propias'],
            ['module' => 'relations', 'action' => 'download_branch', 'code' => 'relations.download_branch', 'description' => 'Descargar relaciones de sucursal'],
            ['module' => 'relations', 'action' => 'download_global', 'code' => 'relations.download_global', 'description' => 'Descargar relaciones globalmente'],
            ['module' => 'bank_imports', 'action' => 'create_branch', 'code' => 'bank_imports.create_branch', 'description' => 'Cargar archivo bancario externo en sucursal'],
            ['module' => 'bank_imports', 'action' => 'view_branch', 'code' => 'bank_imports.view_branch', 'description' => 'Consultar importaciones bancarias de sucursal'],
            ['module' => 'bank_movements', 'action' => 'view_branch', 'code' => 'bank_movements.view_branch', 'description' => 'Consultar movimientos bancarios de sucursal'],
            ['module' => 'bank_imports', 'action' => 'view_global', 'code' => 'bank_imports.view_global', 'description' => 'Consultar importaciones bancarias globalmente'],
            ['module' => 'bank_movements', 'action' => 'view_global', 'code' => 'bank_movements.view_global', 'description' => 'Consultar movimientos bancarios globalmente'],
            ['module' => 'payment_clarifications', 'action' => 'create_own', 'code' => 'payment_clarifications.create_own', 'description' => 'Crear aclaraciones propias con evidencia'],
            ['module' => 'manual_reconciliation', 'action' => 'request_branch', 'code' => 'manual_reconciliation.request_branch', 'description' => 'Solicitar conciliación manual en sucursal'],
            ['module' => 'manual_reconciliation', 'action' => 'authorize_branch', 'code' => 'manual_reconciliation.authorize_branch', 'description' => 'Autorizar conciliación manual de sucursal'],
            ['module' => 'manual_reconciliation', 'action' => 'authorize_global', 'code' => 'manual_reconciliation.authorize_global', 'description' => 'Autorizar conciliación manual global'],
            ['module' => 'manual_reconciliation', 'action' => 'execute_branch', 'code' => 'manual_reconciliation.execute_branch', 'description' => 'Ejecutar conciliación manual autorizada'],
            ['module' => 'surpluses', 'action' => 'view_own', 'code' => 'surpluses.view_own', 'description' => 'Consultar excedentes propios'],
            ['module' => 'surpluses', 'action' => 'view_branch', 'code' => 'surpluses.view_branch', 'description' => 'Consultar excedentes de sucursal'],
            ['module' => 'surpluses', 'action' => 'view_global', 'code' => 'surpluses.view_global', 'description' => 'Consultar excedentes globalmente'],
            ['module' => 'refunds', 'action' => 'authorize_branch', 'code' => 'refunds.authorize_branch', 'description' => 'Autorizar devoluciones de sucursal'],
            ['module' => 'refunds', 'action' => 'authorize_global', 'code' => 'refunds.authorize_global', 'description' => 'Autorizar devoluciones globalmente'],
            ['module' => 'refunds', 'action' => 'execute_branch', 'code' => 'refunds.execute_branch', 'description' => 'Registrar devolución externa ejecutada'],
            ['module' => 'points', 'action' => 'view_own', 'code' => 'points.view_own', 'description' => 'Consultar puntos propios'],
            ['module' => 'points', 'action' => 'redeem_own', 'code' => 'points.redeem_own', 'description' => 'Solicitar canje propio'],
            ['module' => 'points', 'action' => 'authorize_branch', 'code' => 'points.authorize_branch', 'description' => 'Autorizar canjes de sucursal'],
            ['module' => 'points', 'action' => 'authorize_global', 'code' => 'points.authorize_global', 'description' => 'Autorizar canjes globalmente'],
            ['module' => 'points', 'action' => 'deliver_branch', 'code' => 'points.deliver_branch', 'description' => 'Registrar entrega de canje en sucursal'],
            ['module' => 'risk', 'action' => 'view_assigned', 'code' => 'risk.view_assigned', 'description' => 'Consultar alertas de distribuidoras asignadas'],
            ['module' => 'risk', 'action' => 'view_own', 'code' => 'risk.view_own', 'description' => 'Consultar bloqueo propio de morosidad'],
            ['module' => 'risk', 'action' => 'view_branch', 'code' => 'risk.view_branch', 'description' => 'Consultar alertas de sucursal'],
            ['module' => 'risk', 'action' => 'view_global', 'code' => 'risk.view_global', 'description' => 'Consultar alertas globalmente'],
            ['module' => 'delinquency', 'action' => 'decide_branch', 'code' => 'delinquency.decide_branch', 'description' => 'Decidir morosidad de sucursal'],
            ['module' => 'delinquency', 'action' => 'decide_global', 'code' => 'delinquency.decide_global', 'description' => 'Decidir morosidad globalmente'],
            ['module' => 'delinquency_removal', 'action' => 'request_assigned', 'code' => 'delinquency_removal.request_assigned', 'description' => 'Preparar retiro asignado'],
            ['module' => 'delinquency_removal', 'action' => 'decide_branch', 'code' => 'delinquency_removal.decide_branch', 'description' => 'Decidir retiro en sucursal'],
            ['module' => 'delinquency_removal', 'action' => 'decide_global', 'code' => 'delinquency_removal.decide_global', 'description' => 'Decidir retiro globalmente'],
            ['module' => 'client_transfers', 'action' => 'view', 'code' => 'client_transfers.view', 'description' => 'Consultar transferencias visibles'],
            ['module' => 'client_transfers', 'action' => 'initiate_own', 'code' => 'client_transfers.initiate_own', 'description' => 'Iniciar transferencia de cliente propio'],
            ['module' => 'client_transfers', 'action' => 'receive_own', 'code' => 'client_transfers.receive_own', 'description' => 'Preaceptar y aceptar transferencias recibidas'],
            ['module' => 'client_transfers', 'action' => 'decide_assigned', 'code' => 'client_transfers.decide_assigned', 'description' => 'Decidir salida de transferencias asignadas'],
            ['module' => 'organization_changes', 'action' => 'view', 'code' => 'organization_changes.view', 'description' => 'Consultar historial de cambios organizacionales'],
            ['module' => 'organization_changes', 'action' => 'manage_branch', 'code' => 'organization_changes.manage_branch', 'description' => 'Ejecutar cambios dentro de sucursal'],
            ['module' => 'organization_changes', 'action' => 'manage_global', 'code' => 'organization_changes.manage_global', 'description' => 'Ejecutar cambios globales'],
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
                    ? ['users.view', 'roles.view', 'roles.assign', 'branches.view', 'assignments.manage', 'distributor_applications.view', 'distributor_applications.view_sensitive', 'distributor_applications.create', 'distributor_applications.update', 'distributor_applications.submit']
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
                    'distributor_applications.view_sensitive',
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
                    'vouchers.view_branch',
                    'voucher_modifications.authorize_branch',
                    'relations.view_branch', 'relations.download_branch',
                    'bank_imports.view_branch', 'bank_movements.view_branch',
                    'manual_reconciliation.authorize_branch',
                    'surpluses.view_branch', 'refunds.authorize_branch',
                    'points.authorize_branch', 'points.deliver_branch',
                    'risk.view_branch', 'delinquency.decide_branch', 'delinquency_removal.decide_branch',
                    'client_transfers.view', 'organization_changes.view', 'organization_changes.manage_branch',
                ]);
            }

            if ($roleData['code'] === 'admin') {
                $this->assignPerms($role, [
                    'distributors.view_any', 'distributors.view', 'distributors.view_category_history',
                    'distributors.view_initial_credit', 'clients.view', 'clients.view_assignment_history',
                    'clients.view_bank_accounts', 'clients.view_portfolio',
                    'credit_lines.view_global', 'credit_line_movements.view_global', 'credit_increase_requests.view_global',
                    'vouchers.view_global',
                    'relations.view_global',
                    'bank_imports.view_global', 'bank_movements.view_global',
                    'surpluses.view_global',
                    'risk.view_global',
                    'client_transfers.view', 'organization_changes.view',
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
                    'vouchers.view_assigned',
                    'voucher_modifications.authorize_branch',
                    'bank_movements.view_branch',
                    'manual_reconciliation.authorize_branch',
                    'risk.view_assigned', 'delinquency_removal.request_assigned',
                    'client_transfers.view', 'client_transfers.decide_assigned',
                ]);
            }

            if ($roleData['code'] === 'distributor') {
                $this->assignPerms($role, [
                    'distributors.view', 'distributors.view_category_history', 'distributors.view_initial_credit',
                    'clients.view', 'clients.create', 'clients.view_bank_accounts',
                    'clients.manage_bank_accounts', 'clients.view_portfolio', 'clients.manage_portfolio',
                    'credit_lines.view_own', 'credit_line_movements.view_own',
                    'credit_increase_requests.create_own', 'credit_increase_requests.view_own',
                    'vouchers.create_own', 'vouchers.view_own',
                    'relations.view_own', 'relations.download_own',
                    'payment_clarifications.create_own',
                    'surpluses.view_own',
                    'points.view_own', 'points.redeem_own',
                    'risk.view_own',
                    'client_transfers.view', 'client_transfers.initiate_own', 'client_transfers.receive_own',
                ]);
            }

            if ($roleData['code'] === 'cashier') {
                $this->assignPerms($role, ['vouchers.view_branch', 'vouchers.cash_branch', 'relations.view_branch', 'bank_imports.create_branch', 'bank_imports.view_branch', 'bank_movements.view_branch', 'manual_reconciliation.request_branch', 'manual_reconciliation.execute_branch', 'surpluses.view_branch', 'refunds.execute_branch']);
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
