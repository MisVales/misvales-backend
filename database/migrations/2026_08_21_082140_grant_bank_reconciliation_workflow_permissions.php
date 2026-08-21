<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['module' => 'payment_clarifications', 'action' => 'view_own', 'code' => 'payment_clarifications.view_own', 'description' => 'Consultar aclaraciones propias'],
            ['module' => 'payment_clarifications', 'action' => 'view_assigned', 'code' => 'payment_clarifications.view_assigned', 'description' => 'Consultar aclaraciones de distribuidoras asignadas'],
            ['module' => 'payment_clarifications', 'action' => 'view_branch', 'code' => 'payment_clarifications.view_branch', 'description' => 'Consultar aclaraciones de sucursal'],
            ['module' => 'payment_clarifications', 'action' => 'view_global', 'code' => 'payment_clarifications.view_global', 'description' => 'Consultar aclaraciones globalmente'],
            ['module' => 'manual_reconciliation', 'action' => 'view_assigned', 'code' => 'manual_reconciliation.view_assigned', 'description' => 'Consultar conciliaciones manuales asignadas'],
            ['module' => 'manual_reconciliation', 'action' => 'view_branch', 'code' => 'manual_reconciliation.view_branch', 'description' => 'Consultar conciliaciones manuales de sucursal'],
            ['module' => 'manual_reconciliation', 'action' => 'view_global', 'code' => 'manual_reconciliation.view_global', 'description' => 'Consultar conciliaciones manuales globalmente'],
        ];

        foreach ($permissions as $permission) {
            $existingId = DB::table('permissions')->where('code', $permission['code'])->value('id');
            if ($existingId === null) {
                DB::table('permissions')->insert($permission + [
                    'id' => (string) Str::uuid(),
                    'is_sensitive' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('permissions')->where('id', $existingId)->update($permission + ['is_active' => true, 'updated_at' => now()]);
            }
        }

        $matrix = [
            'general_manager' => array_column($permissions, 'code'),
            'admin' => ['payment_clarifications.view_global', 'manual_reconciliation.view_global'],
            'branch_manager' => ['payment_clarifications.view_branch', 'manual_reconciliation.view_branch'],
            'coordinator' => ['payment_clarifications.view_assigned', 'manual_reconciliation.view_assigned'],
            'distributor' => ['payment_clarifications.view_own'],
            'cashier' => ['payment_clarifications.view_branch', 'manual_reconciliation.view_branch'],
        ];

        foreach ($matrix as $roleCode => $permissionCodes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($permissionCodes as $permissionCode) {
                $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');
                if ($permissionId !== null && ! DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'granted_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Forward-only: permission history remains auditable.
    }
};
