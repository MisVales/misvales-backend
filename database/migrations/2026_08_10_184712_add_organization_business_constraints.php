<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Las restricciones activas canonicas ya se crean mediante columnas
        // generadas e indices unicos compatibles con MariaDB.
    }

    public function down(): void
    {
    }
};
