<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enforce maximum 1 active branch_manager per branch
        $roleId = DB::table('roles')->where('code', 'branch_manager')->value('id');

        if ($roleId) {
            DB::statement("
                CREATE UNIQUE INDEX unique_active_branch_manager 
                ON user_role_scopes (branch_id) 
                WHERE status = 'ACTIVE' AND role_id = '{$roleId}'
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_active_branch_manager');
    }
};
