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
        // Las publicaciones programadas existentes pasan a estar vigentes ahora.
        // La aplicación ya no permite capturar ni programar fechas de activación.
        DB::table('category_versions')
            ->where('status', 'PUBLISHED')
            ->where('effective_from', '>', DB::raw('CURRENT_TIMESTAMP'))
            ->update([
                'effective_from' => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // La fecha programada original no se conserva; esta normalización es intencionalmente irreversible.
    }
};
