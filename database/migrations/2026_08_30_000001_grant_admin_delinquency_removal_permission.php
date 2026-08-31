<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', 'admin')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'delinquency_removal.decide_global')->value('id');

        if ($roleId === null || $permissionId === null) {
            return;
        }

        if (DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->whereNull('revoked_at')
            ->exists()) {
            return;
        }

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'granted_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Forward-only: permission history remains auditable.
    }
};
