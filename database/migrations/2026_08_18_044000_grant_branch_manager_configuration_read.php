<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $branchManagerId = DB::table('roles')->where('code', 'branch_manager')->value('id');
        $configurationReadId = DB::table('permissions')->where('code', 'catalogs.view_published')->value('id');

        if ($branchManagerId === null || $configurationReadId === null) {
            return;
        }

        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $branchManagerId, 'permission_id' => $configurationReadId],
            ['id' => (string) Str::uuid(), 'granted_at' => now()],
        );
    }

    public function down(): void
    {
        $branchManagerId = DB::table('roles')->where('code', 'branch_manager')->value('id');
        $configurationReadId = DB::table('permissions')->where('code', 'catalogs.view_published')->value('id');

        if ($branchManagerId !== null && $configurationReadId !== null) {
            DB::table('role_permissions')
                ->where('role_id', $branchManagerId)
                ->where('permission_id', $configurationReadId)
                ->delete();
        }
    }
};
