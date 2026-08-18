<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_increase_state_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('credit_increase_requests')->restrictOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('reason')->nullable();

            $table->timestampTz('created_at');

            // Helpful indices for querying history
            $table->index(['request_id', 'created_at']);
        });

        $estados = "'REQUESTED', 'REJECTED_BY_COORDINATOR', 'PREAUTHORIZED', 'REJECTED_BY_MANAGER', 'AUTHORIZED_PARTIAL', 'AUTHORIZED_TOTAL', 'COMPLETED'";
        DB::statement("ALTER TABLE credit_increase_state_transitions ADD CONSTRAINT credit_increase_state_transitions_from_status_check CHECK (from_status IN ({$estados}))");
        DB::statement("ALTER TABLE credit_increase_state_transitions ADD CONSTRAINT credit_increase_state_transitions_to_status_check CHECK (to_status IN ({$estados}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_increase_state_transitions');
    }
};
