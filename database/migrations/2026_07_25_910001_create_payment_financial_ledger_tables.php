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
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('relation_id', 128);
            $table->foreignUuid('bank_movement_id')->nullable()->constrained('bank_movements')->restrictOnDelete();
            $table->uuid('excess_application_id')->nullable();
            $table->string('source_type', 24);
            $table->decimal('received_amount', 18, 4);
            $table->decimal('applied_amount', 18, 4);
            $table->decimal('excess_amount', 18, 4)->default(0);
            $table->decimal('late_fee_amount', 18, 4)->default(0);
            $table->decimal('interest_amount', 18, 4)->default(0);
            $table->decimal('insurance_amount', 18, 4)->default(0);
            $table->decimal('loan_commission_amount', 18, 4)->default(0);
            $table->decimal('capital_amount', 18, 4)->default(0);
            $table->decimal('balance_before', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->timestampTz('effective_at');
            $table->timestampTz('applied_at');
            $table->string('application_mode', 24);
            $table->uuid('manual_reconciliation_id')->nullable();
            $table->string('idempotency_key', 180)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique('bank_movement_id');
            $table->index(['relation_id', 'effective_at']);
        });

        Schema::create('payment_allocation_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_allocation_id')->constrained('payment_allocations')->restrictOnDelete();
            $table->string('relation_item_id', 128);
            $table->string('voucher_id', 128);
            $table->decimal('late_fee_amount', 18, 4)->default(0);
            $table->decimal('interest_amount', 18, 4)->default(0);
            $table->decimal('insurance_amount', 18, 4)->default(0);
            $table->decimal('loan_commission_amount', 18, 4)->default(0);
            $table->decimal('capital_amount', 18, 4)->default(0);
            $table->json('pending_before');
            $table->json('pending_after');
            $table->timestampTz('created_at');
            $table->unique(['payment_allocation_id', 'relation_item_id']);
        });

        Schema::create('payment_late_fee_markers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('relation_id', 128);
            $table->string('event_key', 160);
            $table->decimal('amount', 18, 4);
            $table->string('configuration_version_id', 128);
            $table->timestampTz('effective_at');
            $table->timestampTz('created_at');
            $table->unique(['relation_id', 'event_key']);
        });

        Schema::create('payment_post_due_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('relation_id', 128);
            $table->date('due_date');
            $table->string('result', 20);
            $table->foreignUuid('bank_import_id')->constrained('bank_imports')->restrictOnDelete();
            $table->decimal('balance_evaluated', 18, 4);
            $table->timestampTz('evaluated_at');
            $table->string('idempotency_key', 180)->unique();
            $table->unique(['relation_id', 'due_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocation_source_check
                    CHECK (source_type IN ('BANK_MOVEMENT', 'CREDIT_BALANCE'));
                ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocation_mode_check
                    CHECK (application_mode IN ('AUTOMATIC', 'MANUAL', 'CREDIT_BALANCE'));
                ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocation_amounts_check CHECK (
                    received_amount > 0 AND applied_amount >= 0 AND excess_amount >= 0
                    AND late_fee_amount >= 0 AND interest_amount >= 0 AND insurance_amount >= 0
                    AND loan_commission_amount >= 0 AND capital_amount >= 0
                    AND balance_before >= 0 AND balance_after >= 0
                    AND received_amount = applied_amount + excess_amount
                    AND applied_amount = late_fee_amount + interest_amount + insurance_amount
                        + loan_commission_amount + capital_amount
                    AND balance_after = balance_before - applied_amount
                );
                ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocation_origin_check CHECK (
                    (source_type = 'BANK_MOVEMENT' AND bank_movement_id IS NOT NULL AND excess_application_id IS NULL)
                    OR (source_type = 'CREDIT_BALANCE' AND bank_movement_id IS NULL AND excess_application_id IS NOT NULL)
                );
                ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocation_manual_check CHECK (
                    (application_mode = 'MANUAL' AND manual_reconciliation_id IS NOT NULL)
                    OR (application_mode <> 'MANUAL' AND manual_reconciliation_id IS NULL)
                );
                ALTER TABLE payment_allocation_items ADD CONSTRAINT payment_allocation_items_amounts_check CHECK (
                    late_fee_amount >= 0 AND interest_amount >= 0 AND insurance_amount >= 0
                    AND loan_commission_amount >= 0 AND capital_amount >= 0
                );
                ALTER TABLE payment_post_due_evaluations ADD CONSTRAINT payment_evaluation_result_check
                    CHECK (result IN ('LIQUIDO', 'ABONO', 'NO_PAGO') AND balance_evaluated >= 0);

                CREATE OR REPLACE FUNCTION protect_payment_ledger() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Payment ledger records are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER payment_allocations_immutable BEFORE UPDATE OR DELETE ON payment_allocations
                    FOR EACH ROW EXECUTE FUNCTION protect_payment_ledger();
                CREATE TRIGGER payment_allocation_items_immutable BEFORE UPDATE OR DELETE ON payment_allocation_items
                    FOR EACH ROW EXECUTE FUNCTION protect_payment_ledger();
                CREATE TRIGGER payment_late_fee_markers_immutable BEFORE UPDATE OR DELETE ON payment_late_fee_markers
                    FOR EACH ROW EXECUTE FUNCTION protect_payment_ledger();
                CREATE TRIGGER payment_post_due_evaluations_immutable BEFORE UPDATE OR DELETE ON payment_post_due_evaluations
                    FOR EACH ROW EXECUTE FUNCTION protect_payment_ledger();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS protect_payment_ledger() CASCADE');
        }
        Schema::dropIfExists('payment_post_due_evaluations');
        Schema::dropIfExists('payment_late_fee_markers');
        Schema::dropIfExists('payment_allocation_items');
        Schema::dropIfExists('payment_allocations');
    }
};
