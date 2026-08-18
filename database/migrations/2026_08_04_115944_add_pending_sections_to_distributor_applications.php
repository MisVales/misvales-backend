<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // La columna ya forma parte de la creación canónica de distributor_applications.
    }

    public function down(): void
    {
        // Se revierte junto con la tabla canónica.
    }
};
