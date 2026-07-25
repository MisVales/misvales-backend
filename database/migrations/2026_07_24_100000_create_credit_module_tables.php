<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('distributor_id')->unique()->constrained('users')->restrictOnDelete();
            $table->decimal('total_authorized', 18, 4);
            $table->decimal('used_balance', 18, 4)->default(0);
            $table->decimal('available_balance', 18, 4);
            $table->decimal('recovered_capital_total', 18, 4)->default(0);
            $table->unsignedBigInteger('last_movement_id')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('credit_line_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('credit_line_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->decimal('total_delta', 18, 4);
            $table->decimal('used_delta', 18, 4);
            $table->decimal('total_before', 18, 4);
            $table->decimal('total_after', 18, 4);
            $table->decimal('used_before', 18, 4);
            $table->decimal('used_after', 18, 4);
            $table->decimal('available_before', 18, 4);
            $table->decimal('available_after', 18, 4);
            $table->string('source_type', 80);
            $table->string('source_id', 128);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->text('reason');
            $table->json('configuration_snapshot')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->string('idempotency_key', 160)->unique();
            $table->timestampsTz();
            $table->unique(['source_type', 'source_id', 'type'], 'credit_movement_source_unique');
            $table->index(['credit_line_id', 'occurred_at']);
            $table->index(['credit_line_id', 'type', 'occurred_at']);
        });

        Schema::table('credit_lines', function (Blueprint $table): void {
            $table->foreign('last_movement_id')->references('id')->on('credit_line_movements')->restrictOnDelete();
        });

        Schema::create('credit_usage_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('credit_line_id')->constrained()->restrictOnDelete();
            $table->string('trigger_type', 40);
            $table->string('trigger_id', 128);
            $table->decimal('base_total_authorized', 18, 4);
            $table->decimal('percentage', 7, 4);
            $table->decimal('tolerance_amount', 18, 4);
            $table->decimal('reference_amount', 18, 4);
            $table->string('status', 20)->index();
            $table->string('bound_voucher_id', 128)->nullable();
            $table->timestampTz('bound_at')->nullable();
            $table->string('consumed_by_voucher_id', 128)->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['trigger_type', 'trigger_id'], 'credit_restriction_trigger_unique');
            $table->index(['credit_line_id', 'status']);
        });

        Schema::create('credit_increase_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('folio', 32)->unique();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('credit_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('coordinator_id')->constrained('users')->restrictOnDelete();
            $table->decimal('requested_amount', 18, 4);
            $table->decimal('recommended_amount', 18, 4)->nullable();
            $table->decimal('authorized_amount', 18, 4)->nullable();
            $table->string('origin_type', 40);
            $table->decimal('product_amount', 18, 4)->nullable();
            $table->decimal('available_balance_snapshot', 18, 4);
            $table->decimal('required_difference', 18, 4)->nullable();
            $table->decimal('total_authorized_snapshot', 18, 4);
            $table->decimal('used_balance_snapshot', 18, 4);
            $table->unsignedBigInteger('credit_line_version_snapshot');
            $table->string('status', 40);
            $table->text('request_reason');
            $table->text('coordinator_reason')->nullable();
            $table->text('manager_reason')->nullable();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('restriction_id')->nullable()->constrained('credit_usage_restrictions')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->string('idempotency_key', 160);
            $table->timestampsTz();
            $table->unique(['distributor_id', 'idempotency_key'], 'credit_increase_idempotency_unique');
            $table->index(['distributor_id', 'requested_at']);
            $table->index(['branch_id', 'status', 'requested_at']);
            $table->index(['coordinator_id', 'status', 'requested_at']);
        });

        Schema::create('credit_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('event_type', 128)->index();
            $table->string('result', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('authorizer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('distributor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('role_code', 64)->nullable();
            $table->string('resource_type', 80)->nullable();
            $table->string('resource_id', 128)->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('metadata')->nullable();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 160)->nullable();
            $table->uuid('correlation_id');
            $table->string('session_id', 128)->nullable();
            $table->string('device_id', 128)->nullable();
            $table->string('display_timezone', 64);
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE credit_lines ADD CONSTRAINT credit_lines_valid_balances CHECK (
                    total_authorized >= 0 AND used_balance >= 0 AND available_balance >= 0
                    AND recovered_capital_total >= 0 AND used_balance <= total_authorized
                    AND available_balance = total_authorized - used_balance
                );
                ALTER TABLE credit_line_movements ADD CONSTRAINT credit_movements_type_check CHECK (
                    type IN ('INITIAL_AUTHORIZATION', 'INCREASE', 'VOUCHER_FULFILLED', 'CAPITAL_RECOVERED', 'AUTHORIZED_CORRECTION')
                );
                ALTER TABLE credit_line_movements ADD CONSTRAINT credit_movements_balances_check CHECK (
                    total_before >= 0 AND total_after >= 0 AND used_before >= 0 AND used_after >= 0
                    AND available_before >= 0 AND available_after >= 0
                    AND used_before <= total_before AND used_after <= total_after
                    AND available_before = total_before - used_before
                    AND available_after = total_after - used_after
                );
                ALTER TABLE credit_line_movements ADD CONSTRAINT credit_movement_effect_check CHECK (
                    (type = 'INITIAL_AUTHORIZATION' AND total_delta > 0 AND used_delta = 0)
                    OR (type = 'INCREASE' AND total_delta > 0 AND used_delta = 0)
                    OR (type = 'VOUCHER_FULFILLED' AND total_delta = 0 AND used_delta > 0)
                    OR (type = 'CAPITAL_RECOVERED' AND total_delta = 0 AND used_delta < 0)
                    OR type = 'AUTHORIZED_CORRECTION'
                );
                ALTER TABLE credit_usage_restrictions ADD CONSTRAINT credit_restriction_status_check
                    CHECK (status IN ('ACTIVE', 'BOUND', 'CONSUMED'));
                ALTER TABLE credit_usage_restrictions ADD CONSTRAINT credit_restriction_values_check
                    CHECK (base_total_authorized >= 0 AND percentage > 0 AND percentage <= 1
                        AND tolerance_amount >= 0 AND reference_amount >= 0);
                ALTER TABLE credit_increase_requests ADD CONSTRAINT credit_increase_status_check CHECK (
                    status IN ('SOLICITADO', 'PREAUTORIZADO', 'RECHAZADO_COORDINADOR', 'RECHAZADO_GERENTE',
                        'AUTORIZADO_TOTAL', 'AUTORIZADO_PARCIAL', 'RESTRICCION_50_ACTIVA', 'COMPLETADO')
                );
                ALTER TABLE credit_increase_requests ADD CONSTRAINT credit_increase_origin_check
                    CHECK (origin_type IN ('NORMAL', 'INSUFFICIENT_CREDIT'));
                ALTER TABLE credit_increase_requests ADD CONSTRAINT credit_increase_amounts_check CHECK (
                    requested_amount > 0 AND available_balance_snapshot >= 0
                    AND total_authorized_snapshot >= 0 AND used_balance_snapshot >= 0
                    AND (recommended_amount IS NULL OR recommended_amount > 0)
                    AND (authorized_amount IS NULL OR authorized_amount > 0)
                );

                CREATE OR REPLACE FUNCTION protect_credit_ledger() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Credit ledger records are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER credit_movements_immutable BEFORE UPDATE OR DELETE ON credit_line_movements
                    FOR EACH ROW EXECUTE FUNCTION protect_credit_ledger();
                CREATE TRIGGER credit_audits_immutable BEFORE UPDATE OR DELETE ON credit_audit_events
                    FOR EACH ROW EXECUTE FUNCTION protect_credit_ledger();
                CREATE TRIGGER credit_lines_no_delete BEFORE DELETE ON credit_lines
                    FOR EACH ROW EXECUTE FUNCTION protect_credit_ledger();
                CREATE TRIGGER credit_requests_no_delete BEFORE DELETE ON credit_increase_requests
                    FOR EACH ROW EXECUTE FUNCTION protect_credit_ledger();
                CREATE TRIGGER credit_restrictions_no_delete BEFORE DELETE ON credit_usage_restrictions
                    FOR EACH ROW EXECUTE FUNCTION protect_credit_ledger();

                CREATE OR REPLACE FUNCTION enforce_credit_line_materialization() RETURNS trigger AS $$
                BEGIN
                    IF (NEW.total_authorized, NEW.used_balance, NEW.available_balance, NEW.recovered_capital_total)
                        IS DISTINCT FROM
                       (OLD.total_authorized, OLD.used_balance, OLD.available_balance, OLD.recovered_capital_total)
                       AND (NEW.lock_version <> OLD.lock_version + 1
                            OR NEW.last_movement_id IS NULL
                            OR NEW.last_movement_id IS NOT DISTINCT FROM OLD.last_movement_id) THEN
                        RAISE EXCEPTION 'A credit balance change requires one new movement and version';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER credit_line_materialization BEFORE UPDATE ON credit_lines
                    FOR EACH ROW EXECUTE FUNCTION enforce_credit_line_materialization();

                CREATE OR REPLACE FUNCTION enforce_credit_request_transition() RETURNS trigger AS $$
                BEGIN
                    IF NEW.status IS NOT DISTINCT FROM OLD.status THEN
                        IF OLD.status IN ('RECHAZADO_COORDINADOR', 'RECHAZADO_GERENTE', 'COMPLETADO') THEN
                            RAISE EXCEPTION 'A terminal credit request is immutable';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF NOT (
                        (OLD.status = 'SOLICITADO' AND NEW.status IN ('PREAUTORIZADO', 'RECHAZADO_COORDINADOR'))
                        OR (OLD.status = 'PREAUTORIZADO' AND NEW.status IN ('AUTORIZADO_TOTAL', 'AUTORIZADO_PARCIAL', 'RECHAZADO_GERENTE'))
                        OR (OLD.status IN ('AUTORIZADO_TOTAL', 'AUTORIZADO_PARCIAL') AND NEW.status = 'RESTRICCION_50_ACTIVA')
                        OR (OLD.status = 'RESTRICCION_50_ACTIVA' AND NEW.status = 'COMPLETADO')
                    ) THEN
                        RAISE EXCEPTION 'Invalid credit request transition: % to %', OLD.status, NEW.status;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER credit_request_transition BEFORE UPDATE ON credit_increase_requests
                    FOR EACH ROW EXECUTE FUNCTION enforce_credit_request_transition();

                CREATE OR REPLACE FUNCTION enforce_credit_restriction_transition() RETURNS trigger AS $$
                BEGIN
                    IF NEW.status IS NOT DISTINCT FROM OLD.status THEN RETURN NEW; END IF;
                    IF NOT (
                        (OLD.status = 'ACTIVE' AND NEW.status = 'BOUND')
                        OR (OLD.status = 'BOUND' AND NEW.status IN ('ACTIVE', 'CONSUMED'))
                    ) THEN
                        RAISE EXCEPTION 'Invalid credit restriction transition: % to %', OLD.status, NEW.status;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER credit_restriction_transition BEFORE UPDATE ON credit_usage_restrictions
                    FOR EACH ROW EXECUTE FUNCTION enforce_credit_restriction_transition();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_credit_ledger() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_credit_line_materialization() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_credit_request_transition() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_credit_restriction_transition() CASCADE');
        }

        Schema::dropIfExists('credit_audit_events');
        Schema::dropIfExists('credit_increase_requests');
        Schema::dropIfExists('credit_usage_restrictions');
        Schema::table('credit_lines', function (Blueprint $table): void {
            $table->dropForeign(['last_movement_id']);
        });
        Schema::dropIfExists('credit_line_movements');
        Schema::dropIfExists('credit_lines');
    }
};
