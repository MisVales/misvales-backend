<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('distributors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('distributor_number')->unique();
            $table->uuid('onboarding_application_id')->unique();
            $table->uuid('user_id')->unique();
            $table->uuid('branch_id');
            $table->string('status')->default('ACTIVE');
            $table->timestamp('activated_at');
            $table->uuid('activated_by')->nullable();
            $table->uuid('activation_operation_id')->unique();
            $table->integer('lock_version')->default(1);
            $table->timestamps();

            $table->index('distributor_number');
            $table->index('onboarding_application_id');
            $table->index('user_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index('activated_at');
            
            // Índice compuesto
            $table->index(['branch_id', 'status', 'distributor_number']);
        });

        Schema::create('distributor_category_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('distributor_id');
            $table->uuid('category_id');
            $table->uuid('category_version_id');
            $table->decimal('profit_rate_snapshot', 8, 4);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->uuid('assigned_by');
            $table->string('assigned_role');
            $table->uuid('assigned_branch_id');
            $table->string('reason');
            $table->string('idempotency_key');
            $table->timestamps();

            // Clave foránea con eliminación restringida (no cascade para proteger historial)
            $table->foreign('distributor_id')->references('id')->on('distributors')->onDelete('restrict');

            // Índice compuesto
            $table->index(['distributor_id', 'effective_from', 'effective_to'], 'distributor_category_assignments_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distributor_category_assignments');
        Schema::dropIfExists('distributors');
    }
};
