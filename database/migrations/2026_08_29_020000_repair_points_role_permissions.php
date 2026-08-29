<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $rolePermissions = [
            'distributor' => ['points.view_own', 'points.request_own'],
            'branch_manager' => ['points.view_branch', 'points.authorize_branch', 'points.deliver_branch'],
            'general_manager' => ['points.view_global', 'points.authorize_global', 'points.deliver_branch', 'points.view_branch'],
            'cashier' => ['points.view_branch', 'points.deliver_branch'],
            'admin' => ['points.view_global'],
            'coordinator' => ['points.view_branch'],
        ];

        foreach ($rolePermissions as $roleCode => $permissionCodes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($permissionCodes as $permissionCode) {
                $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');

                if ($permissionId === null || DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->whereNull('revoked_at')
                    ->exists()) {
                    continue;
                }

                DB::table('role_permissions')->insert([
                    'id' => Str::uuid()->toString(),
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'granted_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No-op: role permission history is append-only.
    }
};
