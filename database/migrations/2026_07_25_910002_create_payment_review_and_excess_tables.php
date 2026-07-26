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
        Schema::create('payment_clarifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('case_number', 40)->unique();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('relation_id', 128)->nullable();
            $table->decimal('reported_amount', 18, 4);
            $table->date('reported_date');
            $table->string('reported_reference', 255);
            $table->string('reported_bank_folio', 160)->nullable();
            $table->text('description');
            $table->string('evidence_media_file_id', 160);
            $table->string('status', 32);
            $table->foreignUuid('linked_movement_id')->nullable()->constrained('bank_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['distributor_id', 'created_at']);
            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::create('manual_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('case_number', 40)->unique();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('relation_id', 128);
            $table->foreignUuid('bank_movement_id')->constrained('bank_movements')->restrictOnDelete();
            $table->foreignUuid('clarification_id')->nullable()->constrained('payment_clarifications')->restrictOnDelete();
            $table->string('evidence_media_file_id', 160);
            $table->string('status', 32);
            $table->text('reason');
            $table->text('decision_reason')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('authorization_id', 160)->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('authorization_expires_at')->nullable();
            $table->timestampTz('authorization_consumed_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['branch_id', 'status', 'requested_at']);
            $table->index(['distributor_id', 'status', 'requested_at']);
            $table->index(['bank_movement_id', 'status']);
        });

        Schema::create('excess_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('origin_relation_id', 128);
            $table->foreignUuid('bank_movement_id')->constrained('bank_movements')->restrictOnDelete();
            $table->decimal('original_amount', 18, 4);
            $table->decimal('available_amount', 18, 4);
            $table->decimal('applied_amount', 18, 4)->default(0);
            $table->decimal('reserved_refund_amount', 18, 4)->default(0);
            $table->decimal('refunded_amount', 18, 4)->default(0);
            $table->string('status', 32);
            $table->string('decision', 32)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->unique('bank_movement_id');
            $table->index(['distributor_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('excess_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('excess_balance_id')->constrained('excess_balances')->restrictOnDelete();
            $table->string('relation_id', 128);
            $table->decimal('amount', 18, 4);
            $table->decimal('available_before', 18, 4);
            $table->decimal('available_after', 18, 4);
            $table->timestampTz('effective_at');
            $table->string('idempotency_key', 180)->unique();
            $table->timestampTz('created_at');
            $table->index(['excess_balance_id', 'created_at']);
        });

        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->foreign('excess_application_id')->references('id')->on('excess_applications')->restrictOnDelete();
            $table->foreign('manual_reconciliation_id')->references('id')->on('manual_reconciliations')->restrictOnDelete();
        });

        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('excess_balance_id')->constrained('excess_balances')->restrictOnDelete();
            $table->foreignId('distributor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('status', 32);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('request_reason');
            $table->text('decision_reason')->nullable();
            $table->string('refund_method', 80)->nullable();
            $table->string('refund_reference', 160)->nullable();
            $table->string('evidence_media_file_id', 160)->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['branch_id', 'status', 'requested_at']);
            $table->index(['distributor_id', 'status', 'requested_at']);
        });

        Schema::create('payment_idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 100);
            $table->char('key_hmac', 64);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_id', 'operation', 'key_hmac']);
        });

        Schema::create('payment_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 128)->index();
            $table->string('result', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 64)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('resource_type', 80)->nullable();
            $table->string('resource_id', 128)->nullable();
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('request_id');
            $table->timestampTz('occurred_at')->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE payment_clarifications ADD CONSTRAINT payment_clarification_status_check CHECK (
                    status IN ('REGISTRADA', 'EN_REVISION', 'MOVIMIENTO_VINCULADO', 'SIN_COINCIDENCIA',
                        'CONCILIACION_SOLICITADA', 'RESUELTA', 'RECHAZADA')
                );
                ALTER TABLE manual_reconciliations ADD CONSTRAINT manual_reconciliation_status_check CHECK (
                    status IN ('BORRADOR', 'PENDIENTE_AUTORIZACION', 'AUTORIZADA', 'RECHAZADA',
                        'APLICADA', 'VENCIDA', 'CANCELADA')
                );
                ALTER TABLE excess_balances ADD CONSTRAINT excess_status_check CHECK (
                    status IN ('PENDIENTE_DECISION', 'SALDO_A_FAVOR', 'APLICADO_PARCIAL',
                        'APLICADO_TOTAL', 'DEVOLUCION_PENDIENTE', 'DEVUELTO')
                );
                ALTER TABLE excess_balances ADD CONSTRAINT excess_amounts_check CHECK (
                    original_amount > 0 AND available_amount >= 0 AND applied_amount >= 0
                    AND reserved_refund_amount >= 0 AND refunded_amount >= 0
                    AND original_amount = available_amount + applied_amount + reserved_refund_amount + refunded_amount
                );
                ALTER TABLE excess_applications ADD CONSTRAINT excess_application_amounts_check CHECK (
                    amount > 0 AND available_before >= amount AND available_after = available_before - amount
                );
                ALTER TABLE refund_requests ADD CONSTRAINT refund_status_check CHECK (
                    status IN ('PENDIENTE_AUTORIZACION', 'AUTORIZADA', 'RECHAZADA', 'CANCELADA', 'COMPLETADA')
                    AND amount > 0
                );

                CREATE TRIGGER excess_applications_immutable BEFORE UPDATE OR DELETE ON excess_applications
                    FOR EACH ROW EXECUTE FUNCTION protect_payment_ledger();
                CREATE TRIGGER payment_audits_immutable BEFORE UPDATE OR DELETE ON payment_audits
                    FOR EACH ROW EXECUTE FUNCTION protect_payment_ledger();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_audits');
        Schema::dropIfExists('payment_idempotency_keys');
        Schema::dropIfExists('refund_requests');
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->dropForeign(['excess_application_id']);
            $table->dropForeign(['manual_reconciliation_id']);
        });
        Schema::dropIfExists('excess_applications');
        Schema::dropIfExists('excess_balances');
        Schema::dropIfExists('manual_reconciliations');
        Schema::dropIfExists('payment_clarifications');
    }
};
