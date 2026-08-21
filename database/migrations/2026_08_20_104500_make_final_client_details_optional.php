<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->text('curp_ciphertext')->nullable()->change();
            $table->char('curp_hmac', 64)->nullable()->change();
            $table->date('birth_date')->nullable()->change();
            $table->string('birth_place')->nullable()->change();
            $table->string('birth_state')->nullable()->change();
            $table->string('birth_city')->nullable()->change();
            $table->string('official_id_type', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->text('curp_ciphertext')->nullable(false)->change();
            $table->char('curp_hmac', 64)->nullable(false)->change();
            $table->date('birth_date')->nullable(false)->change();
            $table->string('birth_place')->nullable(false)->change();
            $table->string('birth_state')->nullable(false)->change();
            $table->string('birth_city')->nullable(false)->change();
            $table->string('official_id_type', 32)->nullable(false)->change();
        });
    }
};
