<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemption_periods', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->decimal('point_value', 19, 4)->nullable()->after('status');
            $table->foreignUuid('point_value_configuration_version_id')->nullable()->after('point_value')->constrained('configuration_versions')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_point_value CHECK (point_value IS NULL OR point_value > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_operational_point_value CHECK (status IN ('DRAFT', 'CANCELLED') OR (point_value IS NOT NULL AND point_value_configuration_version_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT chk_rp_point_value');
        }

        Schema::table('redemption_periods', function (Blueprint $table) {
            $table->dropColumn('description');
            $table->dropConstrainedForeignId('point_value_configuration_version_id');
            $table->dropColumn('point_value');
        });
    }
};
