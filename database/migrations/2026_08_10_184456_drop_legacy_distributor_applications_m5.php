<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distributor_applications_m5')) {
            return;
        }

        $dependencias = collect(DB::select(<<<'SQL'
            SELECT tc.table_name AS tabla, tc.constraint_name AS conname
            FROM information_schema.table_constraints AS tc
            INNER JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_catalog = tc.constraint_catalog
                AND ccu.constraint_schema = tc.constraint_schema
                AND ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY'
                AND tc.table_schema = current_schema()
                AND ccu.table_schema = current_schema()
                AND ccu.table_name = 'distributor_applications_m5'
            SQL));
        if ($dependencias !== []) {
            $detalle = collect($dependencias)->map(fn ($fk) => $fk->tabla.'.'.$fk->conname)->implode(', ');
            throw new RuntimeException('No se puede retirar la raíz legacy; conserva FKs funcionales: '.$detalle);
        }

        Schema::drop('distributor_applications_m5');
    }

    public function down(): void
    {
        throw new RuntimeException('La raíz duplicada no se recrea después de consolidarse.');
    }
};
