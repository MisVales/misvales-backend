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
            ['module' => 'roles', 'action' => 'view', 'code' => 'roles.view', 'description' => 'Ver roles y permisos del sistema'],
            ['module' => 'roles', 'action' => 'assign', 'code' => 'roles.assign', 'description' => 'Asignar roles, sucursales y alcances operativos'],
            ['module' => 'roles', 'action' => 'manage_permissions', 'code' => 'roles.manage_permissions', 'description' => 'Modificar permisos de un rol'],
            ['module' => 'sessions', 'action' => 'view_global', 'code' => 'sessions.view_global', 'description' => 'Ver sesiones de otros usuarios'],
            ['module' => 'sessions', 'action' => 'revoke_global', 'code' => 'sessions.revoke_global', 'description' => 'Revocar sesiones de otros usuarios'],
            ['module' => 'catalogs', 'action' => 'manage', 'code' => 'catalogs.manage', 'description' => 'Crear, modificar y publicar catálogos y configuraciones'],
            ['module' => 'catalogs', 'action' => 'view_history', 'code' => 'catalogs.view_history', 'description' => 'Consultar historial y versiones de catálogos'],
            ['module' => 'catalogs', 'action' => 'view_published', 'code' => 'catalogs.view_published', 'description' => 'Consultar catálogos y configuraciones vigentes'],
            
            // --- Modulo 5 Permisos Granulares ---
            ['module' => 'applications', 'action' => 'review', 'code' => 'distributor_applications.review', 'description' => 'Revisar expediente'],
            ['module' => 'applications', 'action' => 'return_to_draft', 'code' => 'distributor_applications.return_to_draft', 'description' => 'Devolver solicitud a captura'],
            ['module' => 'visits', 'action' => 'assign', 'code' => 'verification_visits.assign', 'description' => 'Asignar visita a verificador'],
            ['module' => 'visits', 'action' => 'view', 'code' => 'verification_visits.view', 'description' => 'Consultar visitas asignadas'],
            ['module' => 'visits', 'action' => 'start', 'code' => 'verification_visits.start', 'description' => 'Iniciar visita física'],
            ['module' => 'visits', 'action' => 'update', 'code' => 'verification_visits.update', 'description' => 'Actualizar datos de visita'],
            ['module' => 'visits', 'action' => 'complete', 'code' => 'verification_visits.complete', 'description' => 'Finalizar visita física'],
            ['module' => 'evidences', 'action' => 'create', 'code' => 'verification_evidences.create', 'description' => 'Subir evidencia fotográfica'],
            ['module' => 'evidences', 'action' => 'view', 'code' => 'verification_evidences.view', 'description' => 'Consultar evidencias'],
            ['module' => 'evidences', 'action' => 'delete', 'code' => 'verification_evidences.delete', 'description' => 'Eliminar evidencia de visita en progreso'],
            ['module' => 'corrections', 'action' => 'view', 'code' => 'application_corrections.view', 'description' => 'Consultar diferencias reportadas'],
            ['module' => 'corrections', 'action' => 'apply', 'code' => 'application_corrections.apply', 'description' => 'Aplicar correcciones a expediente'],
            ['module' => 'evaluations', 'action' => 'view', 'code' => 'application_evaluations.view', 'description' => 'Consultar evaluación de coordinador'],
            ['module' => 'evaluations', 'action' => 'decide', 'code' => 'application_evaluations.decide', 'description' => 'Dictaminar evaluación (Cumple/No Cumple)'],
            ['module' => 'authorizations', 'action' => 'view', 'code' => 'application_authorizations.view', 'description' => 'Consultar autorización gerencial'],
            ['module' => 'authorizations', 'action' => 'decide', 'code' => 'application_authorizations.decide', 'description' => 'Dictaminar autorización gerencial'],
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
                $this->assignPerms($role, Permission::pluck('code')->toArray());
            } elseif ($roleData['code'] === 'branch_manager') {
                $this->assignPerms($role, ['catalogs.view_published', 'application_authorizations.view', 'application_authorizations.decide']);
            } elseif ($roleData['code'] === 'coordinator') {
                $this->assignPerms($role, ['catalogs.view_published', 'distributor_applications.review', 'distributor_applications.return_to_draft', 'verification_visits.assign', 'application_corrections.view', 'application_corrections.apply', 'application_evaluations.view', 'application_evaluations.decide']);
            } elseif ($roleData['code'] === 'verifier') {
                $this->assignPerms($role, ['catalogs.view_published', 'verification_visits.view', 'verification_visits.start', 'verification_visits.update', 'verification_visits.complete', 'verification_evidences.create', 'verification_evidences.view', 'verification_evidences.delete']);
            } elseif ($roleData['code'] === 'admin') {
                $this->assignPerms($role, ['catalogs.view_history', 'catalogs.view_published', 'distributor_applications.review', 'verification_visits.view', 'verification_evidences.view', 'application_corrections.view', 'application_evaluations.view', 'application_authorizations.view']);
            } elseif (in_array($roleData['code'], ['distributor', 'cashier'])) {
                $this->assignPerms($role, ['catalogs.view_published']);
            }
        }
    }

    private function assignPerms(Role $role, array $codes) {
        $permissions = Permission::whereIn('code', $codes)->get();
        $syncData = [];
        foreach ($permissions as $perm) {
            $syncData[$perm->id] = ['id' => Str::uuid()->toString(), 'granted_at' => now()];
        }
        $role->permissions()->syncWithoutDetaching($syncData);
    }
}
