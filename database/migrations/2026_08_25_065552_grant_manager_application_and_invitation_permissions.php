<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $matrix = [
            'general_manager' => [
                'users.create',
                'roles.view',
                'distributor_applications.create',
            ],
            'branch_manager' => [
                'users.create',
                'roles.view',
                'distributor_applications.create',
            ],
        ];

        foreach ($matrix as $roleCode => $permissionCodes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($permissionCodes as $permissionCode) {
                $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');

                if ($permissionId === null) {
                    continue;
                }

                $alreadyGranted = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $alreadyGranted) {
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
        // Forward-only: authorization grants remain auditable.
    }
};
