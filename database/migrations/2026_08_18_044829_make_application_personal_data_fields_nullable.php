<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_personal_data', function (Blueprint $table) {
            $table->string('first_name')->nullable()->change();
            $table->string('first_last_name')->nullable()->change();
            $table->date('birth_date')->nullable()->change();
            $table->string('birth_state')->nullable()->change();
            $table->string('birth_city')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('phone_number', 32)->nullable()->change();
            $table->string('official_id_type', 32)->nullable()->change();
            $table->text('official_id_number_ciphertext')->nullable()->change();
            $table->char('official_id_number_hmac', 64)->nullable()->change();
            $table->string('nationality', 32)->nullable()->change();
            $table->string('birth_country', 2)->nullable()->change();
            $table->string('identification_country', 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('application_personal_data', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('first_last_name')->nullable(false)->change();
            $table->date('birth_date')->nullable(false)->change();
            $table->string('birth_state')->nullable(false)->change();
            $table->string('birth_city')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('phone_number', 32)->nullable(false)->change();
            $table->string('official_id_type', 32)->nullable(false)->change();
            $table->text('official_id_number_ciphertext')->nullable(false)->change();
            $table->char('official_id_number_hmac', 64)->nullable(false)->change();
            $table->string('nationality', 32)->nullable(false)->change();
            $table->string('birth_country', 2)->nullable(false)->change();
            $table->string('identification_country', 2)->nullable(false)->change();
        });
    }
};
