<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', 'coordinator')->value('id');
        $permissionId = DB::table('permissions')
            ->where('code', 'voucher_modifications.authorize_branch')
            ->value('id');

        if ($roleId === null || $permissionId === null) {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => 'Solo gerentes pueden autorizar correcciones de clientes.',
            ]);
    }

    public function down(): void
    {
        // Reparación aditiva: no se reabre una autorización revocada al hacer rollback.
    }
};
