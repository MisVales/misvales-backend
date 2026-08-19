<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table): void {
            // Las columnas se conservan para explicar las versiones históricas
            // creadas antes de separar el catálogo de las condiciones financieras.
            $table->decimal('loan_commission_percentage', 9, 6)->nullable()->change();
            $table->decimal('simple_interest_percentage', 9, 6)->nullable()->change();
            $table->decimal('insurance_amount', 19, 4)->nullable()->change();
            $table->smallInteger('fortnights_count')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible de forma segura: nuevas versiones de producto pueden no
        // tener condiciones financieras. Una corrección futura debe conservar
        // esos registros y no inventar valores para restaurar NOT NULL.
    }
};
