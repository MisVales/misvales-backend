<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // La raíz canónica se crea antes de las tablas M05 para que todas sus FKs
        // nazcan apuntando a la misma identidad. Esta migración se conserva como
        // marcador histórico del antiguo orden de M04.
    }

    public function down(): void
    {
        // La raíz pertenece a 2026_08_04_112940 y se revierte allí.
    }
};
