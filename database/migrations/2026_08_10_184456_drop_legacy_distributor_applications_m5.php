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

        $dependencias = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->selectRaw('TABLE_NAME AS tabla, CONSTRAINT_NAME AS conname')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('REFERENCED_TABLE_NAME', 'distributor_applications_m5')
            ->get();
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
