<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('distributor_applications');
            $table->string('vehicle_type', 64);
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('ownership_status', 32)->nullable();
            $table->jsonb('details_payload')->nullable();
            $table->timestampsTz();

            $table->index('application_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE application_vehicles ADD CONSTRAINT application_vehicles_model_year_check CHECK (model_year IS NULL OR model_year BETWEEN 1886 AND 2200)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('application_vehicles');
    }
};
