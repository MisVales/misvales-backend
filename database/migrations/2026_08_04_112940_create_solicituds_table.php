<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('distributor_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('applicant_data')->comment('Datos de la aspirante');
            $table->string('status', 50)->default('COORDINATOR_REVIEW');
            $table->integer('lock_version')->default(1);
            $table->uuid('branch_id');
            $table->uuid('coordinator_id')->nullable();
            $table->uuid('verifier_id')->nullable();
            $table->uuid('manager_id')->nullable();
            $table->timestampsTz();
            
            // No onDelete('cascade')
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('coordinator_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('verifier_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('manager_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::dropIfExists('distributor_applications');
    }
};
