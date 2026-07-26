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
        $vouchersAvailable = Schema::hasTable('vouchers');

        if ($vouchersAvailable) {
            Schema::table('vouchers', function (Blueprint $table): void {
                if (! Schema::hasColumn('vouchers', 'lock_version')) {
                    $table->unsignedInteger('lock_version')->default(1);
                }
                if (! Schema::hasColumn('vouchers', 'client_name_normalized')) {
                    $table->string('client_name_normalized', 360)->nullable();
                }
                if (! Schema::hasColumn('vouchers', 'opened_by')) {
                    $table->foreignId('opened_by')->nullable()->constrained('users')->restrictOnDelete();
                }
                if (! Schema::hasColumn('vouchers', 'opened_at')) {
                    $table->timestampTz('opened_at')->nullable();
                }
                if (! Schema::hasColumn('vouchers', 'released_by')) {
                    $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
                }
                if (! Schema::hasColumn('vouchers', 'released_at')) {
                    $table->timestampTz('released_at')->nullable();
                }
                if (! Schema::hasColumn('vouchers', 'rejected_by')) {
                    $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
                }
                if (! Schema::hasColumn('vouchers', 'rejected_at')) {
                    $table->timestampTz('rejected_at')->nullable();
                }
                if (! Schema::hasColumn('vouchers', 'rejection_reason_code')) {
                    $table->string('rejection_reason_code', 64)->nullable();
                }
                if (! Schema::hasColumn('vouchers', 'rejection_description')) {
                    $table->string('rejection_description', 500)->nullable();
                }
                if (! Schema::hasColumn('vouchers', 'fulfilled_by')) {
                    $table->foreignId('fulfilled_by')->nullable()->constrained('users')->restrictOnDelete();
                }
                if (! Schema::hasColumn('vouchers', 'fulfilled_at')) {
                    $table->timestampTz('fulfilled_at')->nullable();
                }
            });
            if (
                Schema::hasColumn('vouchers', 'branch_id')
                && Schema::hasColumn('vouchers', 'status')
                && Schema::hasColumn('vouchers', 'generated_at')
            ) {
                Schema::table('vouchers', function (Blueprint $table): void {
                    $table->index(
                        ['branch_id', 'status', 'generated_at'],
                        'vouchers_counter_scope_index',
                    );
                });
            }
            if (Schema::hasColumn('vouchers', 'client_name_normalized')) {
                Schema::table('vouchers', function (Blueprint $table): void {
                    $table->index('client_name_normalized', 'vouchers_counter_client_name_index');
                });
            }
        }

        Schema::create('data_change_requests', function (Blueprint $table) use ($vouchersAvailable): void {
            $table->uuid('id')->primary();
            $table->uuid('voucher_id');
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('operation', 80);
            $table->json('authorized_fields');
            $table->string('reason', 500);
            $table->string('status', 32);
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->json('target_lock_versions');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            if ($vouchersAvailable) {
                $table->foreign('voucher_id')->references('id')->on('vouchers')->restrictOnDelete();
            }
            $table->index(['branch_id', 'status', 'requested_at'], 'data_change_requests_scope_index');
            $table->index(['voucher_id', 'status'], 'data_change_requests_voucher_status_index');
        });

        Schema::create('authorization_tokens', function (Blueprint $table) use ($vouchersAvailable): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('data_change_request_id')->unique()->constrained('data_change_requests')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->uuid('voucher_id');
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('operation', 80);
            $table->json('field_scope');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            if ($vouchersAvailable) {
                $table->foreign('voucher_id')->references('id')->on('vouchers')->restrictOnDelete();
            }
            $table->index(['cashier_id', 'expires_at']);
        });

        Schema::create('voucher_change_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('data_change_request_id')->constrained('data_change_requests')->restrictOnDelete();
            $table->foreignUuid('authorization_token_id')->constrained('authorization_tokens')->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('record_type', 80);
            $table->uuid('record_id')->nullable();
            $table->string('field_identifier', 160);
            $table->text('previous_value_encrypted');
            $table->text('new_value_encrypted');
            $table->foreignId('executed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->uuid('request_id');
            $table->timestampTz('changed_at');
            $table->timestampsTz();
            $table->unique(
                ['data_change_request_id', 'field_identifier'],
                'voucher_change_history_request_field_unique',
            );
        });

        Schema::create('voucher_fulfillments', function (Blueprint $table) use ($vouchersAvailable): void {
            $table->uuid('id')->primary();
            $table->uuid('voucher_id')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('client_bank_account_id')->constrained('client_bank_accounts')->restrictOnDelete();
            $table->decimal('capital_amount', 19, 4);
            $table->text('transaction_number_encrypted')->nullable();
            $table->char('transaction_number_hmac', 64)->nullable()->unique();
            $table->foreignId('released_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at');
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            if ($vouchersAvailable) {
                $table->foreign('voucher_id')->references('id')->on('vouchers')->restrictOnDelete();
            }
        });

        Schema::create('voucher_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 100);
            $table->char('key_hmac', 64);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_id', 'operation', 'key_hmac'], 'voucher_idempotency_actor_operation_key_unique');
        });

        Schema::create('voucher_operation_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('voucher_id');
            $table->string('operation', 100);
            $table->string('status_before', 32)->nullable();
            $table->string('status_after', 32)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->json('protected_context');
            $table->uuid('request_id');
            $table->char('idempotency_key_hmac', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['voucher_id', 'occurred_at']);
        });

        Schema::create('voucher_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 120);
            $table->string('result', 32);
            $table->uuid('voucher_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('effective_role', 64)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->uuid('request_id')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->char('idempotency_key_hmac', 64)->nullable();
            $table->json('protected_context');
            $table->string('error_code', 100)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['voucher_id', 'occurred_at']);
        });

        Schema::create('voucher_outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('aggregate_id');
            $table->string('aggregate_type', 80)->default('VOUCHER');
            $table->string('event_type', 120);
            $table->string('event_key', 190)->unique();
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampsTz();
            $table->index(['published_at', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX data_change_requests_active_voucher_unique
                 ON data_change_requests (voucher_id)
                 WHERE status IN ('PENDIENTE', 'AUTORIZADO')",
            );
            DB::statement(
                "ALTER TABLE data_change_requests ADD CONSTRAINT data_change_request_status_check
                 CHECK (status IN ('PENDIENTE', 'AUTORIZADO', 'RECHAZADO', 'USADO', 'VENCIDO'))",
            );
            DB::statement(
                'ALTER TABLE authorization_tokens ADD CONSTRAINT authorization_token_dates_check
                 CHECK (
                    expires_at = issued_at + INTERVAL \'5 minutes\'
                    AND (consumed_at IS NULL OR consumed_at >= issued_at)
                 )',
            );
            DB::statement(
                'ALTER TABLE voucher_fulfillments ADD CONSTRAINT voucher_fulfillment_capital_check
                 CHECK (capital_amount >= 0)',
            );
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION enforce_data_change_request_transition() RETURNS trigger AS $$
                BEGIN
                    IF OLD.status = NEW.status THEN
                        RETURN NEW;
                    END IF;
                    IF NOT (
                        (OLD.status = 'PENDIENTE' AND NEW.status IN ('AUTORIZADO', 'RECHAZADO'))
                        OR (OLD.status = 'AUTORIZADO' AND NEW.status IN ('USADO', 'VENCIDO'))
                    ) THEN
                        RAISE EXCEPTION 'Invalid data change request transition: % to %', OLD.status, NEW.status;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER data_change_request_transition
                BEFORE UPDATE ON data_change_requests
                FOR EACH ROW EXECUTE FUNCTION enforce_data_change_request_transition();

                CREATE OR REPLACE FUNCTION enforce_authorization_token_transition() RETURNS trigger AS $$
                BEGIN
                    IF OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at THEN
                        RAISE EXCEPTION 'A consumed authorization token cannot be reused';
                    END IF;
                    IF OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN
                        RAISE EXCEPTION 'A revoked authorization token cannot be reactivated';
                    END IF;
                    IF NEW.expires_at IS DISTINCT FROM OLD.expires_at
                        OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
                        OR NEW.field_scope::jsonb IS DISTINCT FROM OLD.field_scope::jsonb THEN
                        RAISE EXCEPTION 'Authorization token scope is immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER authorization_token_transition
                BEFORE UPDATE ON authorization_tokens
                FOR EACH ROW EXECUTE FUNCTION enforce_authorization_token_transition();

                CREATE OR REPLACE FUNCTION enforce_voucher_fulfillment_immutability() RETURNS trigger AS $$
                BEGIN
                    IF OLD.fulfilled_at IS NOT NULL THEN
                        RAISE EXCEPTION 'A fulfilled voucher record is immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER voucher_fulfillment_immutability
                BEFORE UPDATE ON voucher_fulfillments
                FOR EACH ROW EXECUTE FUNCTION enforce_voucher_fulfillment_immutability();

                CREATE OR REPLACE FUNCTION prevent_voucher_evidence_delete() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Voucher evidence cannot be deleted';
                END;
                $$ LANGUAGE plpgsql;
                SQL);

            foreach ([
                'data_change_requests',
                'authorization_tokens',
                'voucher_change_history',
                'voucher_fulfillments',
                'voucher_operation_history',
                'voucher_audits',
                'voucher_outbox_events',
            ] as $table) {
                DB::statement(
                    "CREATE TRIGGER {$table}_no_delete
                     BEFORE DELETE ON {$table}
                     FOR EACH ROW EXECUTE FUNCTION prevent_voucher_evidence_delete()",
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_outbox_events');
        Schema::dropIfExists('voucher_audits');
        Schema::dropIfExists('voucher_operation_history');
        Schema::dropIfExists('voucher_idempotency_keys');
        Schema::dropIfExists('voucher_fulfillments');
        Schema::dropIfExists('voucher_change_history');
        Schema::dropIfExists('authorization_tokens');
        Schema::dropIfExists('data_change_requests');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP FUNCTION IF EXISTS prevent_voucher_evidence_delete() CASCADE;
                 DROP FUNCTION IF EXISTS enforce_voucher_fulfillment_immutability() CASCADE;
                 DROP FUNCTION IF EXISTS enforce_authorization_token_transition() CASCADE;
                 DROP FUNCTION IF EXISTS enforce_data_change_request_transition() CASCADE;',
            );
        }
    }
};
