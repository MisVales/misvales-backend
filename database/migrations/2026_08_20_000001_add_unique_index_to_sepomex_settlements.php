<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS colonias_unique_settlement ON colonias (codigo_postal_id, name, COALESCE(settlement_type, ''))");
        } elseif (DB::getDriverName() === 'mysql') {
            Schema::table('colonias', function (Blueprint $table): void {
                $table->string('settlement_type_unique')->storedAs("COALESCE(settlement_type, '')");
                $table->unique(['codigo_postal_id', 'name', 'settlement_type_unique'], 'colonias_unique_settlement');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS colonias_unique_settlement');
        } elseif (DB::getDriverName() === 'mysql') {
            Schema::table('colonias', function (Blueprint $table): void {
                $table->dropUnique('colonias_unique_settlement');
                $table->dropColumn('settlement_type_unique');
            });
        }
    }
};
