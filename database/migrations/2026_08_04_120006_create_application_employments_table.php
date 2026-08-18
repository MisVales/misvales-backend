<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_employments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('distributor_applications');
            $table->string('employer_name');
            $table->string('job_title')->nullable();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->boolean('is_current')->default(true);
            $table->jsonb('reference_payload')->nullable();
            $table->jsonb('details_payload')->nullable();
            $table->timestampsTz();

            $table->index('application_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE application_employments ADD CONSTRAINT application_employments_dates_check CHECK (ended_at IS NULL OR started_at IS NULL OR ended_at >= started_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('application_employments');
    }
};

