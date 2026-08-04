<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redemption_periods', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->decimal('point_value', 19, 4)->default(1.0000)->after('status'); // default just for safety in case there are records
        });

        DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_point_value CHECK (point_value > 0)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT chk_rp_point_value');
        
        Schema::table('redemption_periods', function (Blueprint $table) {
            $table->dropColumn('description');
            $table->dropColumn('point_value');
        });
    }
};
