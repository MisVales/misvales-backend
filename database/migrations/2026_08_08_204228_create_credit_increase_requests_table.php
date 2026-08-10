<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_increase_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('credit_line_id');
            $table->uuid('distributor_id');
            $table->decimal('requested_amount', 12, 2);
            $table->decimal('recommended_amount', 12, 2)->nullable();
            $table->decimal('authorized_amount', 12, 2)->nullable();
            $table->string('status', 30)->default('PENDING');
            $table->uuid('coordinator_id')->nullable();
            $table->uuid('manager_id')->nullable();
            $table->text('distributor_notes')->nullable();
            $table->text('coordinator_notes')->nullable();
            $table->text('manager_notes')->nullable();
            $table->timestampTz('pre_authorized_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            
            $table->foreign('credit_line_id')->references('id')->on('credit_lines')->restrictOnDelete();
            $table->foreign('distributor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('coordinator_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
            
            $table->index(['distributor_id', 'status']);
            $table->index(['credit_line_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_increase_requests');
    }
};
