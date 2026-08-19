<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // La primera normalización se ejecutó con la zona horaria de PHP en una
        // instancia cuya conexión PostgreSQL usa otra zona. Reafirmamos las
        // versiones futuras usando el reloj de PostgreSQL, que también se usa
        // al persistir los timestamps en esta base.
        DB::table('category_versions')
            ->where('status', 'PUBLISHED')
            ->where('effective_from', '>', DB::raw('CURRENT_TIMESTAMP'))
            ->update([
                'effective_from' => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);
    }

    public function down(): void
    {
        // La fecha programada original no se conserva; esta corrección es irreversible.
    }
};
