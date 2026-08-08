<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('application_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->uuid('verification_visit_id');
            $table->string('section', 50);
            $table->string('field_path');
            $table->text('previous_value_payload');
            $table->text('new_value_payload');
            $table->text('reason');
            $table->uuid('corrected_by');
            $table->timestampTz('corrected_at');
            $table->timestampsTz();

            $table->foreign('application_id')->references('id')->on('distributor_applications')->restrictOnDelete();
            $table->foreign('verification_visit_id')->references('id')->on('verification_visits')->restrictOnDelete();
            $table->foreign('corrected_by')->references('id')->on('users')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::dropIfExists('application_corrections');
    }
};
