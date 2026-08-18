<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_personal_data', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->unique()->constrained('distributor_applications');
            $table->string('first_name');
            $table->string('first_last_name');
            $table->string('second_last_name')->nullable();
            $table->text('curp_ciphertext');
            $table->char('curp_hmac', 64);
            $table->text('rfc_ciphertext')->nullable();
            $table->char('rfc_hmac', 64)->nullable();
            $table->date('birth_date');
            $table->string('birth_place');
            $table->string('birth_state');
            $table->string('birth_city');
            $table->string('email');
            $table->string('phone_number', 32);
            $table->string('official_id_type', 32);
            $table->text('official_id_number_ciphertext');
            $table->char('official_id_number_hmac', 64);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE application_personal_data ADD CONSTRAINT application_personal_data_id_type_check CHECK (official_id_type IN ('INE', 'PASSPORT', 'PROFESSIONAL_LICENSE', 'OTHER'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('application_personal_data');
    }
};

