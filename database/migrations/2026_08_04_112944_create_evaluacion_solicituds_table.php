<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('application_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->uuid('verification_visit_id');
            $table->string('result', 50);
            $table->text('reason');
            $table->jsonb('evaluation_payload')->nullable();
            $table->uuid('evaluated_by');
            $table->timestampTz('evaluated_at');
            $table->timestampsTz();

            $table->foreign('application_id')->references('id')->on('distributor_applications')->restrictOnDelete();
            $table->foreign('verification_visit_id')->references('id')->on('verification_visits')->restrictOnDelete();
            $table->foreign('evaluated_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE application_evaluations ADD CONSTRAINT application_evaluations_result_check CHECK (result IN ('COMPLIES', 'DOES_NOT_COMPLY'))");
        Schema::table('application_evaluations', fn (Blueprint $table) => $table->index(['application_id', 'evaluated_at']));
    }
    public function down(): void {
        Schema::dropIfExists('application_evaluations');
    }
};

