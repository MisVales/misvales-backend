<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('default_scope');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        $this->agregarRestriccionesDeAlcance();
    }

    private function agregarRestriccionesDeAlcance(): void
    {
        DB::statement("
            ALTER TABLE roles 
            ADD CONSTRAINT chk_role_default_scope 
            CHECK (default_scope IN ('GLOBAL', 'BRANCH', 'ASSIGNED', 'SELF'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
