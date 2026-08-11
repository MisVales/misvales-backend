<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('verification_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->uuid('verifier_id');
            $table->uuid('assigned_by');
            $table->timestampTz('assigned_at');
            $table->string('status', 50);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('visited_at')->nullable();
            $table->string('result', 50)->nullable();
            $table->text('observations')->nullable();
            $table->jsonb('differences_payload')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('location_accuracy_meters', 10, 2)->nullable();
            $table->integer('lock_version')->default(1);
            $table->timestampsTz();

            $table->foreign('application_id')->references('id')->on('distributor_applications')->restrictOnDelete();
            $table->foreign('verifier_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE verification_visits ADD CONSTRAINT verification_visits_status_check CHECK (status IN ('ASSIGNED', 'IN_PROGRESS', 'COMPLETED'))");
        DB::statement("ALTER TABLE verification_visits ADD CONSTRAINT verification_visits_result_check CHECK (result IS NULL OR result IN ('FAVORABLE', 'UNFAVORABLE'))");
        DB::statement("ALTER TABLE verification_visits ADD CONSTRAINT verification_visits_state_check CHECK ((status = 'COMPLETED' AND result IS NOT NULL AND completed_at IS NOT NULL) OR (status <> 'COMPLETED' AND result IS NULL AND completed_at IS NULL))");
        Schema::table('verification_visits', fn (Blueprint $table) => $table->index(['application_id', 'assigned_at']));
    }
    public function down(): void {
        Schema::dropIfExists('verification_visits');
    }
};
