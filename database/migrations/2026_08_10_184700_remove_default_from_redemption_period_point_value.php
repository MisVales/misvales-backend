<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('redemption_periods', 'point_value')) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE redemption_periods ALTER COLUMN point_value DROP DEFAULT');
            }
        }

        if (! Schema::hasColumn('redemption_periods', 'point_value_configuration_version_id')) {
            Schema::table('redemption_periods', function (Blueprint $table): void {
                $table->foreignUuid('point_value_configuration_version_id')->nullable()->constrained('configuration_versions')->restrictOnDelete();
            });
        }

        $incompatibles = DB::table('redemption_periods')
            ->where('status', '<>', 'DRAFT')
            ->where(function ($query): void {
                $query->whereNull('point_value')->orWhereNull('point_value_configuration_version_id');
            })
            ->limit(20)
            ->pluck('id');
        if ($incompatibles->isNotEmpty()) {
            throw new RuntimeException('Periodos operativos sin snapshot/configuraciÃ³n de valor de punto. IDs: '.$incompatibles->implode(', '));
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT IF EXISTS chk_rp_point_value');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT IF EXISTS chk_rp_operational_point_value');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_point_value CHECK (point_value IS NULL OR point_value > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_operational_point_value CHECK (status IN ('DRAFT', 'CANCELLED') OR (point_value IS NOT NULL AND point_value_configuration_version_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('redemption_periods', 'point_value')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE redemption_periods ALTER COLUMN point_value SET DEFAULT 1.0000');
            }
        }

        Schema::table('redemption_periods', function (Blueprint $table) {
            $table->dropColumn('point_value_configuration_version_id');
        });
    }
};

