<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cut_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('cut_date')->unique();
            $table->string('business_timezone')->default('America/Monterrey');
            $table->string('status');
            $table->json('configuration_snapshot');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('triggered_by')->nullable();
            $table->string('trigger_type');
            $table->unsignedInteger('distributors_evaluated')->default(0);
            $table->unsignedInteger('relations_generated')->default(0);
            $table->unsignedInteger('distributors_without_items')->default(0);
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cut_runs');
    }
};
