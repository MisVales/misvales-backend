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
        Schema::table('redemption_periods', function (Blueprint $table): void {
            $table->string('public_folio', 80)->nullable()->unique();
            $table->string('name', 160)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('closed_at')->nullable();
        });

        Schema::create('point_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('distributor_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('total_points')->default(0);
            $table->unsignedBigInteger('reserved_points')->default(0);
            $table->unsignedBigInteger('available_points')->default(0);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampTz('last_movement_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('points_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('public_folio', 80)->unique();
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->string('status', 32);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('total_candidates')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('earned_count')->default(0);
            $table->unsignedInteger('penalized_count')->default(0);
            $table->unsignedInteger('no_change_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('initiated_by_type', 40);
            $table->unsignedBigInteger('initiated_by_id')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });

        Schema::create('relation_point_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('relation_id')->unique();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('point_account_id')->constrained('point_accounts')->restrictOnDelete();
            $table->string('classification', 32);
            $table->timestampTz('effective_liquidation_at');
            $table->decimal('products_capital_basis', 19, 4);
            $table->decimal('divisor_snapshot', 19, 4);
            $table->unsignedInteger('multiplier_snapshot');
            $table->decimal('penalty_rate_snapshot', 9, 6);
            $table->jsonb('configuration_version_ids');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('points_earned')->default(0);
            $table->unsignedBigInteger('points_penalized')->default(0);
            $table->unsignedBigInteger('balance_after');
            $table->string('result', 48);
            $table->uuid('source_event_id')->unique();
            $table->foreignUuid('points_run_id')->nullable()->constrained('points_runs')->nullOnDelete();
            $table->timestampTz('processed_at');
            $table->timestampTz('created_at');
            $table->index(['distributor_id', 'processed_at']);
            $table->index(['result', 'processed_at']);
        });

        Schema::create('point_redemption_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('public_folio', 80)->unique();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('point_account_id')->constrained('point_accounts')->restrictOnDelete();
            $table->foreignId('redemption_period_id')->constrained('redemption_periods')->restrictOnDelete();
            $table->foreignId('branch_id_snapshot')->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('requested_points');
            $table->unsignedBigInteger('authorized_points')->nullable();
            $table->decimal('point_value_snapshot', 19, 4)->nullable();
            $table->uuid('point_value_version_id')->nullable();
            $table->decimal('cash_amount', 19, 4)->nullable();
            $table->string('status', 24);
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('value_frozen_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('delivery_method', 80)->nullable();
            $table->string('delivery_reference', 160)->nullable();
            $table->text('delivery_comment')->nullable();
            $table->string('idempotency_key', 150);
            $table->timestampsTz();
            $table->unique(['distributor_id', 'idempotency_key'], 'point_redemption_distributor_idem_unique');
            $table->index(['branch_id_snapshot', 'status', 'requested_at'], 'point_redemption_branch_status_idx');
            $table->index(['distributor_id', 'requested_at']);
        });

        Schema::create('point_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('point_account_id')->constrained('point_accounts')->restrictOnDelete();
            $table->foreignUuid('redemption_request_id')->unique()->constrained('point_redemption_requests')->restrictOnDelete();
            $table->unsignedBigInteger('points');
            $table->string('status', 24);
            $table->timestampTz('reserved_at');
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['point_account_id', 'status']);
        });

        Schema::create('point_redemption_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('redemption_request_id')->constrained('point_redemption_requests')->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 64)->nullable();
            $table->foreignId('branch_id_snapshot')->nullable()->constrained('branches')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 150)->nullable();
            $table->timestampTz('occurred_at');
            $table->jsonb('security_context')->nullable();
            $table->index(['redemption_request_id', 'occurred_at'], 'point_redemption_history_idx');
        });

        Schema::create('points_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('point_account_id')->constrained('point_accounts')->restrictOnDelete();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('direction', 12);
            $table->unsignedBigInteger('points');
            $table->bigInteger('signed_points');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->unsignedBigInteger('reserved_before');
            $table->unsignedBigInteger('reserved_after');
            $table->uuid('relation_id')->nullable();
            $table->foreignUuid('redemption_request_id')->nullable()->constrained('point_redemption_requests')->restrictOnDelete();
            $table->foreignUuid('point_evaluation_id')->nullable()->constrained('relation_point_evaluations')->restrictOnDelete();
            $table->string('rule_code', 80);
            $table->uuid('configuration_version_id')->nullable();
            $table->text('reason');
            $table->uuid('source_event_id')->unique();
            $table->foreignId('branch_id_snapshot')->constrained('branches')->restrictOnDelete();
            $table->string('actor_type', 40);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
            $table->index(['point_account_id', 'occurred_at', 'id']);
            $table->index(['relation_id', 'occurred_at']);
            $table->unique(['point_evaluation_id', 'type'], 'point_ledger_evaluation_type_unique');
            $table->unique(['redemption_request_id', 'type'], 'point_ledger_redemption_type_unique');
        });

        Schema::create('points_run_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('points_run_id')->constrained('points_runs')->cascadeOnDelete();
            $table->uuid('relation_id');
            $table->string('result', 48);
            $table->foreignUuid('point_evaluation_id')->nullable()->constrained('relation_point_evaluations')->nullOnDelete();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('processed_at');
            $table->unique(['points_run_id', 'relation_id']);
        });

        Schema::create('point_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 100)->index();
            $table->string('result', 24);
            $table->string('resource_type', 80);
            $table->string('resource_id', 100);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 64)->nullable();
            $table->foreignId('distributor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('correlation_id', 100);
            $table->string('idempotency_key', 150)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['resource_type', 'resource_id', 'occurred_at'], 'point_audit_resource_idx');
        });

        Schema::create('point_idempotency_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('route', 160);
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_id', 'route', 'idempotency_key'], 'point_http_idempotency_unique');
        });

        $this->addPostgreSqlConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('point_idempotency_records');
        Schema::dropIfExists('point_audit_events');
        Schema::dropIfExists('points_run_items');
        Schema::dropIfExists('points_ledger_entries');
        Schema::dropIfExists('point_redemption_status_history');
        Schema::dropIfExists('point_reservations');
        Schema::dropIfExists('point_redemption_requests');
        Schema::dropIfExists('relation_point_evaluations');
        Schema::dropIfExists('points_runs');
        Schema::dropIfExists('point_accounts');

        Schema::table('redemption_periods', function (Blueprint $table): void {
            $table->dropUnique(['public_folio']);
            $table->dropColumn(['public_folio', 'name', 'description', 'version', 'closed_at']);
        });
    }

    private function addPostgreSqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE point_accounts ADD CONSTRAINT point_accounts_balance_check
                CHECK (total_points >= 0 AND reserved_points >= 0 AND reserved_points <= total_points AND available_points = total_points - reserved_points);
            ALTER TABLE points_ledger_entries ADD CONSTRAINT points_ledger_positive_check CHECK (points > 0);
            ALTER TABLE points_ledger_entries ADD CONSTRAINT points_ledger_enum_check CHECK (
                type IN ('EARNED', 'LATE_PAYMENT_PENALTY', 'REDEEMED') AND direction IN ('CREDIT', 'DEBIT')
            );
            ALTER TABLE points_ledger_entries ADD CONSTRAINT points_ledger_sign_check CHECK (
                (type = 'EARNED' AND direction = 'CREDIT' AND signed_points = points)
                OR (type IN ('LATE_PAYMENT_PENALTY', 'REDEEMED') AND direction = 'DEBIT' AND signed_points = -points)
            );
            ALTER TABLE points_ledger_entries ADD CONSTRAINT points_ledger_source_check CHECK (
                (type IN ('EARNED', 'LATE_PAYMENT_PENALTY') AND relation_id IS NOT NULL AND point_evaluation_id IS NOT NULL AND redemption_request_id IS NULL)
                OR (type = 'REDEEMED' AND relation_id IS NULL AND point_evaluation_id IS NULL AND redemption_request_id IS NOT NULL)
            );
            ALTER TABLE relation_point_evaluations ADD CONSTRAINT relation_point_basis_check CHECK (products_capital_basis >= 0);
            ALTER TABLE relation_point_evaluations ADD CONSTRAINT relation_point_snapshot_check CHECK (
                classification IN ('ANTICIPADA', 'PUNTUAL', 'FUERA_DE_TIEMPO')
                AND divisor_snapshot > 0
                AND multiplier_snapshot > 0
                AND penalty_rate_snapshot >= 0
                AND penalty_rate_snapshot <= 1
                AND points_earned >= 0
                AND points_penalized >= 0
            );
            ALTER TABLE relation_point_evaluations ADD CONSTRAINT relation_point_result_check CHECK (
                result IN ('EARNED', 'PENALIZED', 'NO_CHANGE_PUNCTUAL', 'NO_CHANGE_ZERO_RESULT')
            );
            ALTER TABLE points_runs ADD CONSTRAINT points_runs_status_check CHECK (
                status IN ('PENDING', 'PROCESSING', 'COMPLETED', 'COMPLETED_WITH_ERRORS', 'FAILED')
            );
            ALTER TABLE point_redemption_requests ADD CONSTRAINT point_redemption_requested_positive_check CHECK (requested_points > 0);
            ALTER TABLE point_redemption_requests ADD CONSTRAINT point_redemption_status_check CHECK (
                status IN ('PENDING', 'AUTHORIZED', 'REJECTED', 'COMPLETED')
            );
            ALTER TABLE point_reservations ADD CONSTRAINT point_reservations_positive_check CHECK (points > 0);
            ALTER TABLE point_reservations ADD CONSTRAINT point_reservations_status_check CHECK (
                status IN ('ACTIVE', 'RELEASED', 'CONSUMED')
            );
        SQL);
    }
};
