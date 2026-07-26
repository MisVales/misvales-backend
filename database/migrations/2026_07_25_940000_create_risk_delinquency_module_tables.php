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
        Schema::create('distributor_risk_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('distributor_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('current_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('current_coordinator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('consecutive_breaches')->default(0);
            $table->uuid('last_evaluated_relation_id')->nullable();
            $table->timestampTz('last_evaluated_at')->nullable();
            $table->decimal('overdue_balance', 18, 4)->default(0);
            $table->timestampTz('financially_regularized_at')->nullable();
            $table->string('delinquency_status', 40)->default('NOT_DELINQUENT');
            $table->boolean('blocked_for_new_vouchers')->default(false);
            $table->timestampTz('delinquency_applied_at')->nullable();
            $table->string('profile_status', 40)->default('CURRENT');
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['current_branch_id', 'delinquency_status'], 'risk_profiles_branch_status_idx');
            $table->index('current_coordinator_id', 'risk_profiles_coordinator_idx');
            $table->index('consecutive_breaches', 'risk_profiles_breaches_idx');
        });

        Schema::create('relation_risk_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('relation_id');
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('cut_id', 100);
            $table->timestampTz('cut_at');
            $table->timestampTz('due_at');
            $table->string('source_result', 24)->nullable();
            $table->decimal('overdue_balance_snapshot', 18, 4)->default(0);
            $table->string('evaluation_status', 32);
            $table->string('source_version', 100);
            $table->unsignedInteger('sequence_position')->nullable();
            $table->timestampTz('evaluated_at');
            $table->uuid('supersedes_id')->nullable()->index();
            $table->string('idempotency_key', 200)->unique();
            $table->timestampTz('created_at');
            $table->unique(['relation_id', 'source_version'], 'relation_risk_source_version_unique');
            $table->index(['distributor_id', 'cut_at', 'due_at'], 'relation_risk_order_idx');
            $table->index(['distributor_id', 'evaluation_status'], 'relation_risk_status_idx');
        });

        Schema::create('risk_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40);
            $table->timestampTz('started_at');
            $table->timestampTz('last_incorporated_at')->nullable();
            $table->unsignedInteger('breach_count')->default(0);
            $table->string('reset_reason', 80)->nullable();
            $table->uuid('breaking_relation_id')->nullable();
            $table->timestampTz('regularized_at')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['distributor_id', 'status'], 'risk_sequences_distributor_status_idx');
        });

        Schema::create('risk_sequence_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('risk_sequence_id')->constrained('risk_sequences')->restrictOnDelete();
            $table->foreignUuid('evaluation_id')->constrained('relation_risk_evaluations')->restrictOnDelete();
            $table->uuid('relation_id');
            $table->unsignedInteger('position');
            $table->decimal('overdue_balance_snapshot', 18, 4);
            $table->string('source_result', 24);
            $table->timestampTz('created_at');
            $table->unique(['risk_sequence_id', 'relation_id'], 'risk_sequence_relation_unique');
            $table->unique(['risk_sequence_id', 'position'], 'risk_sequence_position_unique');
        });

        Schema::create('risk_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('alert_number')->unique();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('coordinator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('risk_sequence_id')->constrained('risk_sequences')->restrictOnDelete();
            $table->string('alert_type', 32);
            $table->unsignedInteger('breach_count');
            $table->decimal('overdue_balance_snapshot', 18, 4);
            $table->string('status', 40);
            $table->timestampTz('detected_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->string('idempotency_key', 200)->unique();
            $table->timestampsTz();
            $table->index(['branch_id', 'status', 'detected_at'], 'risk_alert_scope_idx');
            $table->index(['distributor_id', 'detected_at'], 'risk_alert_distributor_idx');
        });

        Schema::create('risk_alert_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('risk_alert_id')->constrained('risk_alerts')->restrictOnDelete();
            $table->foreignUuid('evaluation_id')->constrained('relation_risk_evaluations')->restrictOnDelete();
            $table->uuid('relation_id');
            $table->unsignedInteger('position');
            $table->timestampTz('cut_at');
            $table->timestampTz('due_at');
            $table->string('source_result', 24);
            $table->decimal('overdue_balance_snapshot', 18, 4);
            $table->string('source_version', 100);
            $table->timestampTz('created_at');
            $table->unique(['risk_alert_id', 'relation_id'], 'risk_alert_relation_unique');
        });

        Schema::create('delinquency_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('decision_number')->unique();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('risk_alert_id')->unique()->constrained('risk_alerts')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('decision', 32)->default('APPLIED');
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->string('decided_role', 64);
            $table->foreignId('reauthentication_id')->nullable()->constrained('reauth_authorizations')->restrictOnDelete();
            $table->decimal('overdue_balance_snapshot', 18, 4);
            $table->text('reason')->nullable();
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->timestampTz('decided_at');
            $table->string('idempotency_key', 200)->unique();
            $table->timestampTz('created_at');
            $table->index(['distributor_id', 'decided_at'], 'delinquency_decisions_distributor_idx');
        });

        Schema::create('delinquency_removal_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_number')->unique();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('coordinator_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('delinquency_decision_id')->constrained('delinquency_decisions')->restrictOnDelete();
            $table->string('status', 32);
            $table->decimal('overdue_balance_snapshot', 18, 4)->default(0);
            $table->text('prepared_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decided_role', 64)->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('reauthentication_id')->nullable()->constrained('reauth_authorizations')->restrictOnDelete();
            $table->timestampTz('prepared_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->string('idempotency_key', 200)->unique();
            $table->timestampsTz();
            $table->index(['branch_id', 'status', 'prepared_at'], 'removal_requests_scope_idx');
            $table->index(['distributor_id', 'status'], 'removal_requests_distributor_idx');
        });

        Schema::create('risk_transition_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->string('transition_type', 100);
            $table->string('previous_state', 80)->nullable();
            $table->string('new_state', 80)->nullable();
            $table->uuid('risk_alert_id')->nullable();
            $table->uuid('decision_id')->nullable();
            $table->uuid('removal_request_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 64)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->timestampTz('effective_at');
            $table->timestampTz('created_at');
            $table->index(['distributor_id', 'effective_at'], 'risk_history_distributor_idx');
        });

        Schema::create('risk_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 128);
            $table->string('result', 32);
            $table->string('resource_type', 80);
            $table->string('resource_id', 128);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 64)->nullable();
            $table->foreignId('distributor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('metadata')->nullable();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 200)->nullable();
            $table->uuid('correlation_id');
            $table->string('display_timezone', 64)->default('America/Monterrey');
            $table->timestampTz('operational_at');
            $table->timestampTz('occurred_at');
            $table->index(['event_type', 'occurred_at'], 'risk_audit_event_idx');
        });

        Schema::create('risk_idempotency_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 100);
            $table->string('idempotency_key', 200);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_id', 'operation', 'idempotency_key'], 'risk_http_idempotency_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE distributor_risk_profiles ADD CONSTRAINT risk_profile_values_check CHECK (
                    consecutive_breaches >= 0 AND overdue_balance >= 0
                    AND profile_status IN ('CURRENT', 'REBUILD_REQUIRED', 'REBUILDING', 'INCONSISTENT')
                    AND delinquency_status IN ('NOT_DELINQUENT', 'DELINQUENT', 'REGULARIZED_PENDING_REMOVAL')
                    AND (
                        (delinquency_status = 'NOT_DELINQUENT' AND blocked_for_new_vouchers = FALSE)
                        OR (delinquency_status IN ('DELINQUENT', 'REGULARIZED_PENDING_REMOVAL') AND blocked_for_new_vouchers = TRUE)
                    )
                );
                ALTER TABLE relation_risk_evaluations ADD CONSTRAINT relation_risk_values_check CHECK (
                    overdue_balance_snapshot >= 0
                    AND evaluation_status IN ('PENDING_SOURCE', 'COMPLIANT', 'BREACHED', 'SUPERSEDED')
                    AND (source_result IS NULL OR source_result IN ('LIQUIDO', 'ABONO', 'NO_PAGO'))
                );
                ALTER TABLE risk_sequences ADD CONSTRAINT risk_sequence_values_check CHECK (
                    breach_count >= 0 AND version > 0
                    AND status IN ('ACTIVE', 'RESET_BY_COMPLIANCE', 'RESET_BY_REGULARIZATION', 'SUPERSEDED')
                );
                ALTER TABLE risk_alerts ADD CONSTRAINT risk_alert_values_check CHECK (
                    breach_count IN (1, 2, 3) AND overdue_balance_snapshot >= 0
                    AND alert_type IN ('FIRST_BREACH', 'SECOND_BREACH', 'THIRD_BREACH')
                    AND status IN ('ACTIVE', 'RESOLVED_BY_DECISION', 'FINANCIALLY_REGULARIZED', 'SUPERSEDED')
                );
                ALTER TABLE delinquency_removal_requests ADD CONSTRAINT removal_request_values_check CHECK (
                    overdue_balance_snapshot >= 0
                    AND status IN ('PREPARED', 'APPROVED', 'REJECTED', 'INVALIDATED')
                );
                CREATE UNIQUE INDEX one_prepared_removal_per_distributor
                    ON delinquency_removal_requests(distributor_id) WHERE status = 'PREPARED';

                CREATE OR REPLACE FUNCTION protect_risk_immutable_records() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Risk historical records are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER relation_risk_evaluations_immutable BEFORE UPDATE OR DELETE ON relation_risk_evaluations
                    FOR EACH ROW EXECUTE FUNCTION protect_risk_immutable_records();
                CREATE TRIGGER risk_sequence_relations_immutable BEFORE UPDATE OR DELETE ON risk_sequence_relations
                    FOR EACH ROW EXECUTE FUNCTION protect_risk_immutable_records();
                CREATE TRIGGER risk_alert_relations_immutable BEFORE UPDATE OR DELETE ON risk_alert_relations
                    FOR EACH ROW EXECUTE FUNCTION protect_risk_immutable_records();
                CREATE TRIGGER delinquency_decisions_immutable BEFORE UPDATE OR DELETE ON delinquency_decisions
                    FOR EACH ROW EXECUTE FUNCTION protect_risk_immutable_records();
                CREATE TRIGGER risk_transition_history_immutable BEFORE UPDATE OR DELETE ON risk_transition_history
                    FOR EACH ROW EXECUTE FUNCTION protect_risk_immutable_records();
                CREATE TRIGGER risk_audit_events_immutable BEFORE UPDATE OR DELETE ON risk_audit_events
                    FOR EACH ROW EXECUTE FUNCTION protect_risk_immutable_records();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_risk_immutable_records() CASCADE');
        }
        Schema::dropIfExists('risk_idempotency_records');
        Schema::dropIfExists('risk_audit_events');
        Schema::dropIfExists('risk_transition_history');
        Schema::dropIfExists('delinquency_removal_requests');
        Schema::dropIfExists('delinquency_decisions');
        Schema::dropIfExists('risk_alert_relations');
        Schema::dropIfExists('risk_alerts');
        Schema::dropIfExists('risk_sequence_relations');
        Schema::dropIfExists('risk_sequences');
        Schema::dropIfExists('relation_risk_evaluations');
        Schema::dropIfExists('distributor_risk_profiles');
    }
};
