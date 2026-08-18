<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('permissions')->where('code', 'relations.view_assigned')->exists()) {
            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'code' => 'relations.view_assigned',
                'module' => 'relations',
                'action' => 'view_assigned',
                'description' => 'Consultar relaciones de distribuidoras asignadas',
                'is_sensitive' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $coordinatorId = DB::table('roles')->where('code', 'coordinator')->value('id');
        $assignedRelationsId = DB::table('permissions')->where('code', 'relations.view_assigned')->value('id');

        if ($coordinatorId === null || $assignedRelationsId === null) {
            return;
        }

        $clientPermissionIds = DB::table('permissions')
            ->whereIn('code', [
                'clients.view',
                'clients.view_sensitive',
                'clients.view_assignment_history',
                'clients.view_bank_accounts',
                'clients.view_portfolio',
            ])->pluck('id');

        DB::table('role_permissions')
            ->where('role_id', $coordinatorId)
            ->whereIn('permission_id', $clientPermissionIds)
            ->delete();

        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $coordinatorId, 'permission_id' => $assignedRelationsId],
            ['id' => (string) Str::uuid(), 'granted_at' => now()],
        );
    }

    public function down(): void
    {
        $coordinatorId = DB::table('roles')->where('code', 'coordinator')->value('id');
        $assignedRelationsId = DB::table('permissions')->where('code', 'relations.view_assigned')->value('id');

        if ($coordinatorId !== null && $assignedRelationsId !== null) {
            DB::table('role_permissions')
                ->where('role_id', $coordinatorId)
                ->where('permission_id', $assignedRelationsId)
                ->delete();
        }
    }
};
