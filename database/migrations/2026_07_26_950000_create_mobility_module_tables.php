<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_transfer_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('transfer_number', 40)->unique();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->uuid('origin_distributor_id');
            $table->uuid('recipient_distributor_id');
            $table->foreignId('origin_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('origin_coordinator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 40);
            $table->decimal('total_due_snapshot', 19, 4);
            $table->decimal('overdue_snapshot', 19, 4);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->foreignId('preaccepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('preaccepted_at')->nullable();
            $table->foreignId('origin_decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('origin_decided_at')->nullable();
            $table->foreignId('final_accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('final_accepted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('client_version');
            $table->unsignedBigInteger('portfolio_version');
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->boolean('active_slot')->nullable()->default(true);
            $table->timestampsTz();
            $table->unique(['requested_by', 'idempotency_key']);
            $table->unique(['client_id', 'active_slot']);
            $table->index(['origin_distributor_id', 'status', 'requested_at']);
            $table->index(['recipient_distributor_id', 'status', 'requested_at']);
        });

        Schema::create('administrative_reassignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reassignment_number', 40)->unique();
            $table->string('status', 40);
            $table->foreignId('scope_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('executed_by')->constrained('users')->restrictOnDelete();
            $table->string('executed_role', 40);
            $table->foreignId('reauthentication_id')->nullable()->constrained('reauth_authorizations')->restrictOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->unique(['executed_by', 'idempotency_key']);
        });

        Schema::create('administrative_reassignment_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('administrative_reassignment_id');
            $table->foreign('administrative_reassignment_id', 'mobility_admin_item_batch_fk')
                ->references('id')->on('administrative_reassignments')->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->uuid('origin_distributor_id');
            $table->uuid('destination_distributor_id');
            $table->foreignUuid('origin_assignment_id')->constrained('client_distributor_assignments')->restrictOnDelete();
            $table->foreignUuid('destination_assignment_id')->nullable()->constrained('client_distributor_assignments')->restrictOnDelete();
            $table->decimal('total_due_snapshot', 19, 4);
            $table->decimal('overdue_snapshot', 19, 4);
            $table->unsignedBigInteger('client_version');
            $table->unsignedBigInteger('portfolio_version');
            $table->string('status', 40);
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['administrative_reassignment_id', 'client_id'], 'admin_reassignment_client_unique');
            $table->index(['client_id', 'status']);
        });

        Schema::create('distributor_branch_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('change_number', 40)->unique();
            $table->uuid('distributor_id');
            $table->foreignId('origin_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_coordinator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 50);
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('authorized_role', 40)->nullable();
            $table->foreignId('reauthentication_id')->nullable()->constrained('reauth_authorizations')->restrictOnDelete();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->boolean('active_slot')->nullable()->default(true);
            $table->timestampsTz();
            $table->unique(['requested_by', 'idempotency_key']);
            $table->unique(['distributor_id', 'active_slot']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('branch_change_client_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_change_id')->constrained('distributor_branch_changes')->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->uuid('origin_distributor_id');
            $table->uuid('destination_distributor_id')->nullable();
            $table->foreignUuid('administrative_reassignment_id')->nullable()->constrained('administrative_reassignments')->restrictOnDelete();
            $table->string('status', 40);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['branch_change_id', 'client_id']);
        });

        Schema::create('coordinator_reassignment_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('batch_number', 40)->unique();
            $table->foreignId('outgoing_coordinator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 40);
            $table->text('reason');
            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reauthentication_id')->nullable()->constrained('reauth_authorizations')->restrictOnDelete();
            $table->unsignedInteger('snapshot_count');
            $table->unsignedInteger('current_count')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->boolean('active_slot')->nullable()->default(true);
            $table->timestampsTz();
            $table->unique(['registered_by', 'idempotency_key']);
            $table->unique(['outgoing_coordinator_id', 'active_slot'], 'coordinator_active_batch_unique');
            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::create('coordinator_reassignment_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('coordinator_reassignment_batches')->restrictOnDelete();
            $table->uuid('distributor_id');
            $table->foreignId('origin_coordinator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('destination_coordinator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('origin_assignment_id')->nullable();
            $table->uuid('destination_assignment_id')->nullable();
            $table->string('status', 40);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['batch_id', 'distributor_id']);
            $table->index(['batch_id', 'destination_coordinator_id']);
        });

        Schema::create('mobility_state_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 60);
            $table->uuid('aggregate_id');
            $table->string('previous_state', 50)->nullable();
            $table->string('new_state', 50);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 40);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('use_case', 100);
            $table->text('reason')->nullable();
            $table->uuid('correlation_id');
            $table->jsonb('snapshot')->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['aggregate_type', 'aggregate_id', 'occurred_at'], 'mobility_history_aggregate_idx');
        });

        Schema::create('mobility_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 60);
            $table->uuid('aggregate_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 40)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('result', 30);
            $table->text('reason')->nullable();
            $table->jsonb('before_snapshot')->nullable();
            $table->jsonb('after_snapshot')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['aggregate_type', 'aggregate_id', 'occurred_at'], 'mobility_audit_aggregate_idx');
        });

        Schema::create('mobility_action_idempotency', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->string('aggregate_type', 60);
            $table->uuid('aggregate_id');
            $table->unsignedBigInteger('result_version');
            $table->timestampsTz();
            $table->unique(['actor_user_id', 'action', 'idempotency_key'], 'mobility_action_idempotency_unique');
        });

        $this->addPostgreSqlConstraints();
    }

    private function addPostgreSqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE client_transfer_requests
                ADD CONSTRAINT transfer_distinct_parties CHECK (origin_distributor_id <> recipient_distributor_id),
                ADD CONSTRAINT transfer_status_check CHECK (status IN ('REQUESTED','PREACCEPTED','REJECTED_BY_RECIPIENT','ORIGIN_EXIT_AUTHORIZED','ORIGIN_EXIT_REJECTED','COMPLETED','CANCELLED')),
                ADD CONSTRAINT transfer_balance_snapshot_check CHECK (total_due_snapshot >= 0 AND overdue_snapshot >= 0);
            ALTER TABLE administrative_reassignments
                ADD CONSTRAINT admin_reassignment_status_check CHECK (status IN ('DRAFT','VALIDATED','COMPLETED','REJECTED_BY_VALIDATION','CANCELLED'));
            ALTER TABLE administrative_reassignment_items
                ADD CONSTRAINT admin_reassignment_distinct_parties CHECK (origin_distributor_id <> destination_distributor_id);
            ALTER TABLE distributor_branch_changes
                ADD CONSTRAINT branch_change_distinct_branches CHECK (origin_branch_id <> destination_branch_id),
                ADD CONSTRAINT branch_change_status_check CHECK (status IN ('REQUESTED','AUTHORIZED','CLIENT_REASSIGNMENT_PENDING','DESTINATION_COORDINATOR_PENDING','READY_TO_COMPLETE','COMPLETED','CANCELLED'));
            ALTER TABLE coordinator_reassignment_batches
                ADD CONSTRAINT coordinator_reassignment_status_check CHECK (status IN ('REGISTERED','ASSIGNMENT_PENDING','READY_TO_COMPLETE','COMPLETED','CANCELLED'));

            CREATE OR REPLACE FUNCTION prevent_mobility_history_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'M15 history is immutable';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER mobility_state_history_immutable BEFORE UPDATE OR DELETE ON mobility_state_history
                FOR EACH ROW EXECUTE FUNCTION prevent_mobility_history_change();
            CREATE TRIGGER mobility_audits_immutable BEFORE UPDATE OR DELETE ON mobility_audits
                FOR EACH ROW EXECUTE FUNCTION prevent_mobility_history_change();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_mobility_history_change() CASCADE');
        }
        Schema::dropIfExists('mobility_action_idempotency');
        Schema::dropIfExists('mobility_audits');
        Schema::dropIfExists('mobility_state_history');
        Schema::dropIfExists('coordinator_reassignment_items');
        Schema::dropIfExists('coordinator_reassignment_batches');
        Schema::dropIfExists('branch_change_client_items');
        Schema::dropIfExists('distributor_branch_changes');
        Schema::dropIfExists('administrative_reassignment_items');
        Schema::dropIfExists('administrative_reassignments');
        Schema::dropIfExists('client_transfer_requests');
    }
};
