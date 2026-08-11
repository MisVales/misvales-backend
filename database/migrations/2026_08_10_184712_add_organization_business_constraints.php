<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            // No existe cardinalidad máxima conocida para gerentes de sucursal.
            DB::statement('DROP TRIGGER IF EXISTS trigger_check_branch_manager_cardinality ON user_role_scopes');
            DB::statement('DROP FUNCTION IF EXISTS check_branch_manager_cardinality()');
            DB::statement('DROP INDEX IF EXISTS unique_active_coordinator_distributor');
            DB::statement('DROP INDEX IF EXISTS unique_active_client_distributor');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS unique_active_client_distributor");
            DB::statement("DROP INDEX IF EXISTS unique_active_coordinator_distributor");
        }
    }
};
