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

        $dependencias = DB::select(<<<'SQL'
            SELECT conrelid::regclass::text AS tabla, conname
            FROM pg_constraint
            WHERE contype = 'f'
              AND confrelid = 'distributor_applications_m5'::regclass
        SQL);
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
