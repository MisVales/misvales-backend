<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cut_run_distributors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cut_run_id')->index();
            $table->uuid('distributor_id')->index();
            $table->string('status')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('relation_id')->nullable()->index();
            $table->string('error_code')->nullable();
            $table->json('error_context')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['cut_run_id', 'distributor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cut_run_distributors');
    }
};
