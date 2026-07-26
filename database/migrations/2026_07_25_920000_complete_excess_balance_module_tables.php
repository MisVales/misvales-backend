<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE excess_balances DROP CONSTRAINT IF EXISTS excess_amounts_check');
        }

        Schema::table('excess_balances', function (Blueprint $table): void {
            $table->string('public_number', 32)->nullable()->after('id');
            $table->foreignUuid('payment_allocation_id')->nullable()->after('bank_movement_id')
                ->constrained('payment_allocations')->restrictOnDelete();
            $table->decimal('retained_amount', 18, 4)->default(0)->after('original_amount');
            $table->char('currency', 3)->default('MXN')->after('refunded_amount');
            $table->timestampTz('effective_paid_at')->nullable()->after('status');
        });

        DB::table('excess_balances')
            ->where('status', 'PENDIENTE_DECISION')
            ->update([
                'retained_amount' => DB::raw('available_amount'),
                'available_amount' => 0,
            ]);

        DB::table('excess_balances')
            ->orderBy('id')
            ->each(function (object $balance): void {
                $movement = DB::table('bank_movements')->where('id', $balance->bank_movement_id)->first();
                $allocation = DB::table('payment_allocations')
                    ->where('bank_movement_id', $balance->bank_movement_id)
                    ->orderBy('created_at')
                    ->first();
                DB::table('excess_balances')->where('id', $balance->id)->update([
                    'public_number' => self::folio('EXC'),
                    'payment_allocation_id' => $allocation?->id,
                    'effective_paid_at' => $movement?->paid_at,
                ]);
            });

        Schema::table('excess_balances', function (Blueprint $table): void {
            $table->unique('public_number');
            $table->unique('payment_allocation_id');
            $table->index(['distributor_id', 'status', 'created_at'], 'excess_distributor_status_date_idx');
            $table->index(['branch_id', 'status', 'created_at'], 'excess_branch_status_date_idx');
        });

        Schema::table('excess_applications', function (Blueprint $table): void {
            // Kept as a unique UUID without a reverse FK because M11 already
            // references this table; two immediate FKs would make atomic
            // insertion impossible with the immutable ledgers.
            $table->uuid('payment_allocation_id')->nullable()->unique();
            $table->timestampTz('applied_at')->nullable();
            $table->string('status', 24)->default('APPLIED');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::table('refund_requests', function (Blueprint $table): void {
            $table->string('request_number', 32)->nullable()->after('id');
            $table->date('refund_date')->nullable()->after('decision_reason');
            $table->text('request_reason')->nullable()->change();
        });

        DB::table('refund_requests')
            ->orderBy('id')
            ->each(function (object $request): void {
                DB::table('refund_requests')->where('id', $request->id)->update([
                    'request_number' => self::folio('REF'),
                ]);
            });

        Schema::table('refund_requests', function (Blueprint $table): void {
            $table->unique('request_number');
        });

        Schema::create('excess_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('excess_balance_id')->constrained('excess_balances')->restrictOnDelete();
            $table->string('entry_type', 40);
            $table->decimal('amount', 18, 4);
            $table->string('balance_bucket_from', 24)->nullable();
            $table->string('balance_bucket_to', 24)->nullable();
            $table->foreignUuid('excess_application_id')->nullable()
                ->constrained('excess_applications')->restrictOnDelete();
            $table->foreignUuid('refund_request_id')->nullable()
                ->constrained('refund_requests')->restrictOnDelete();
            $table->string('idempotency_key', 180);
            $table->timestampTz('occurred_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['entry_type', 'idempotency_key'], 'excess_ledger_type_idempotency_unique');
            $table->index(['excess_balance_id', 'occurred_at']);
        });

        Schema::create('excess_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('excess_balance_id')->constrained('excess_balances')->restrictOnDelete();
            $table->foreignUuid('refund_request_id')->nullable()
                ->constrained('refund_requests')->restrictOnDelete();
            $table->foreignUuid('excess_application_id')->nullable()
                ->constrained('excess_applications')->restrictOnDelete();
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->jsonb('amounts_before');
            $table->jsonb('amounts_after');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 24);
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 180)->unique();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
            $table->index(['excess_balance_id', 'occurred_at']);
        });

        Schema::create('excess_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('operation', 100);
            $table->string('resource_id', 128);
            $table->char('key_hmac', 64);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(
                ['actor_id', 'operation', 'resource_id', 'key_hmac'],
                'excess_idempotency_scope_unique',
            );
        });

        Schema::create('excess_processed_events', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->string('event_type', 100);
            $table->string('resource_id', 128);
            $table->string('result', 40);
            $table->timestampTz('processed_at');
            $table->jsonb('response_payload')->nullable();
            $table->unique(['event_type', 'resource_id'], 'excess_event_resource_unique');
        });

        Schema::create('excess_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action', 100)->index();
            $table->string('result', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 64)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('resource_type', 80);
            $table->string('resource_id', 128);
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('correlation_id');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('occurred_at')->index();
        });

        Schema::create('excess_evidence_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('storage_file_id', 160)->unique();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('detected_mime', 120);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('uploaded_at');
            $table->timestampTz('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE excess_balances ADD CONSTRAINT excess_amounts_check CHECK (
                    original_amount > 0
                    AND retained_amount >= 0
                    AND available_amount >= 0
                    AND applied_amount >= 0
                    AND reserved_refund_amount >= 0
                    AND refunded_amount >= 0
                    AND original_amount = retained_amount + available_amount + applied_amount
                        + reserved_refund_amount + refunded_amount
                    AND currency = 'MXN'
                );
                ALTER TABLE excess_balances ADD CONSTRAINT excess_origin_allocation_required
                    CHECK (payment_allocation_id IS NOT NULL) NOT VALID;
                ALTER TABLE excess_balances ADD CONSTRAINT excess_effective_paid_at_required
                    CHECK (effective_paid_at IS NOT NULL) NOT VALID;
                ALTER TABLE excess_ledger_entries ADD CONSTRAINT excess_ledger_entry_check CHECK (
                    amount > 0
                    AND entry_type IN ('EXCESS_DETECTED', 'MARKED_AS_CREDIT', 'RESERVED_FOR_REFUND',
                        'CREDIT_APPLIED', 'REFUND_COMPLETED')
                    AND (balance_bucket_from IS NULL OR balance_bucket_from IN
                        ('RETAINED', 'AVAILABLE', 'RESERVED'))
                    AND (balance_bucket_to IS NULL OR balance_bucket_to IN
                        ('RETAINED', 'AVAILABLE', 'RESERVED', 'APPLIED', 'REFUNDED'))
                );
                ALTER TABLE excess_applications ALTER COLUMN effective_at DROP NOT NULL;
                ALTER TABLE excess_applications ADD CONSTRAINT excess_application_status_check
                    CHECK (status = 'APPLIED');

                CREATE UNIQUE INDEX refund_requests_one_per_excess
                    ON refund_requests (excess_balance_id);

                CREATE OR REPLACE FUNCTION protect_excess_financial_history() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION '% is immutable', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER excess_ledger_entries_immutable
                    BEFORE UPDATE OR DELETE ON excess_ledger_entries
                    FOR EACH ROW EXECUTE FUNCTION protect_excess_financial_history();
                CREATE TRIGGER excess_status_history_immutable
                    BEFORE UPDATE OR DELETE ON excess_status_history
                    FOR EACH ROW EXECUTE FUNCTION protect_excess_financial_history();
                CREATE TRIGGER excess_audits_immutable
                    BEFORE UPDATE OR DELETE ON excess_audits
                    FOR EACH ROW EXECUTE FUNCTION protect_excess_financial_history();
                CREATE TRIGGER excess_evidence_files_immutable
                    BEFORE UPDATE OR DELETE ON excess_evidence_files
                    FOR EACH ROW EXECUTE FUNCTION protect_excess_financial_history();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_excess_financial_history() CASCADE');
            DB::statement('DROP INDEX IF EXISTS refund_requests_one_per_excess');
            DB::statement('ALTER TABLE excess_balances DROP CONSTRAINT IF EXISTS excess_origin_allocation_required');
            DB::statement('ALTER TABLE excess_balances DROP CONSTRAINT IF EXISTS excess_effective_paid_at_required');
            DB::statement('ALTER TABLE excess_balances DROP CONSTRAINT IF EXISTS excess_amounts_check');
            DB::statement('ALTER TABLE excess_applications DROP CONSTRAINT IF EXISTS excess_application_status_check');
        }

        Schema::dropIfExists('excess_evidence_files');
        Schema::dropIfExists('excess_audits');
        Schema::dropIfExists('excess_processed_events');
        Schema::dropIfExists('excess_idempotency_keys');
        Schema::dropIfExists('excess_status_history');
        Schema::dropIfExists('excess_ledger_entries');

        Schema::table('refund_requests', function (Blueprint $table): void {
            $table->dropUnique(['request_number']);
            $table->dropColumn(['request_number', 'refund_date']);
        });
        Schema::table('excess_applications', function (Blueprint $table): void {
            $table->dropUnique(['payment_allocation_id']);
            $table->dropColumn(['payment_allocation_id', 'applied_at', 'status', 'created_by']);
        });
        Schema::table('excess_balances', function (Blueprint $table): void {
            $table->dropIndex('excess_distributor_status_date_idx');
            $table->dropIndex('excess_branch_status_date_idx');
            $table->dropUnique(['public_number']);
            $table->dropForeign(['payment_allocation_id']);
            $table->dropUnique(['payment_allocation_id']);
            $table->dropColumn([
                'public_number',
                'payment_allocation_id',
                'retained_amount',
                'currency',
                'effective_paid_at',
            ]);
        });
    }

    private static function folio(string $prefix): string
    {
        return $prefix.'-'.Str::upper(Str::random(16));
    }
};
