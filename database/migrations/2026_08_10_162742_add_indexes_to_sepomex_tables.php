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
        Schema::table('estados', function (Blueprint $table) {
            $table->index('name');
        });

        Schema::table('municipios', function (Blueprint $table) {
            $table->index(['estado_id', 'name']);
        });

        Schema::table('colonias', function (Blueprint $table) {
            $table->index(['codigo_postal_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('colonias', function (Blueprint $table) {
            $table->dropIndex(['codigo_postal_id', 'name']);
        });

        Schema::table('municipios', function (Blueprint $table) {
            $table->dropIndex(['estado_id', 'name']);
        });

        Schema::table('estados', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });
    }
};
