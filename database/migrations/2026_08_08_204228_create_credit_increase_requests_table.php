<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS credit_increase_request_seq START 1');
        }

        Schema::create('credit_increase_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_number')->unique();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->foreignUuid('credit_line_id')->constrained('credit_lines')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('coordinator_id')->constrained('users')->restrictOnDelete();
            
            $table->string('status');
            
            $table->decimal('requested_amount', 19, 4);
            $table->decimal('recommended_amount', 19, 4)->nullable();
            $table->decimal('authorized_amount', 19, 4)->nullable();
            
            $table->decimal('line_total_at_request', 19, 4);
            $table->decimal('used_balance_at_request', 19, 4);
            $table->decimal('available_balance_at_request', 19, 4);
            
            $table->string('request_reason');
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            
            $table->foreignUuid('coordinator_decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('coordinator_decided_at')->nullable();
            $table->string('coordinator_reason')->nullable();
            
            $table->string('manager_decision')->nullable();
            $table->foreignUuid('manager_decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('manager_decided_at')->nullable();
            $table->string('manager_reason')->nullable();
            
            $table->foreignUuid('restriction_id')->nullable()->constrained('credit_usage_restrictions')->nullOnDelete();
            
            $table->timestampTz('completed_at')->nullable();
            
            $table->integer('lock_version')->default(1);
            
            $table->timestampsTz();

            $table->index(['distributor_id', 'requested_at']);
            $table->index(['credit_line_id', 'requested_at']);
        });

        DB::statement("ALTER TABLE credit_increase_requests ADD CONSTRAINT credit_increase_requests_status_check CHECK (status IN ('REQUESTED', 'REJECTED_BY_COORDINATOR', 'PREAUTHORIZED', 'REJECTED_BY_MANAGER', 'AUTHORIZED_PARTIAL', 'AUTHORIZED_TOTAL', 'COMPLETED'))");
        DB::statement("ALTER TABLE credit_increase_requests ADD CONSTRAINT credit_increase_requests_manager_decision_check CHECK (manager_decision IS NULL OR manager_decision IN ('APPROVE_REQUESTED', 'APPROVE_LOWER', 'REJECT'))");
        DB::statement('ALTER TABLE credit_increase_requests ADD CONSTRAINT credit_increase_requests_amounts_check CHECK (requested_amount > 0 AND (recommended_amount IS NULL OR recommended_amount > 0) AND (authorized_amount IS NULL OR (authorized_amount > 0 AND authorized_amount <= requested_amount)))');
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_increase_requests ADD CONSTRAINT credit_increase_requests_snapshot_check CHECK (line_total_at_request > 0 AND used_balance_at_request >= 0 AND used_balance_at_request <= line_total_at_request AND available_balance_at_request >= 0 AND available_balance_at_request = line_total_at_request - used_balance_at_request)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credit_increase_requests ADD CONSTRAINT credit_increase_requests_lock_version_check CHECK (lock_version >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_increase_requests');
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('DROP SEQUENCE IF EXISTS credit_increase_request_seq');
        }
    }
};

