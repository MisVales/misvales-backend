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

        foreach ($rolePermissions as $roleCode => $permCodes) {
            $role = DB::table('roles')->where('code', $roleCode)->first();
            if (! $role) {
                continue;
            }

            foreach ($permCodes as $code) {
                $permission = DB::table('permissions')->where('code', $code)->first();
                if (! $permission) {
                    continue;
                }

                $exists = DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permission->id)
                    ->whereNull('revoked_at')
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'role_id' => $role->id,
                        'permission_id' => $permission->id,
                        'granted_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // No-op
    }
};
