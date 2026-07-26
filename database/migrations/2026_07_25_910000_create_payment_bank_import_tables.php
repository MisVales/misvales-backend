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
        Schema::create('bank_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('media_file_id', 160);
            $table->date('business_date');
            $table->char('file_hash', 64);
            $table->string('original_name', 255);
            $table->string('status', 24);
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->unsignedBigInteger('valid_rows')->default(0);
            $table->unsignedBigInteger('invalid_rows')->default(0);
            $table->unsignedBigInteger('reconciled_rows')->default(0);
            $table->unsignedBigInteger('unreconciled_rows')->default(0);
            $table->unsignedBigInteger('duplicate_rows')->default(0);
            $table->json('headers')->nullable();
            $table->json('file_metadata')->nullable();
            $table->json('error_summary')->nullable();
            $table->uuid('repeated_of_id')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('processing_finished_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['branch_id', 'business_date', 'status']);
            $table->index(['file_hash', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('bank_imports', function (Blueprint $table): void {
            $table->foreign('repeated_of_id')->references('id')->on('bank_imports')->restrictOnDelete();
        });

        Schema::create('bank_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_import_id')->constrained('bank_imports')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('row_number');
            $table->string('payment_reference_raw', 255)->nullable();
            $table->string('payment_reference_normalized', 255)->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->string('bank_folio_raw', 160)->nullable();
            $table->string('bank_folio_normalized', 160)->nullable();
            $table->string('bank_folio_scope', 160)->nullable();
            $table->text('concept_raw')->nullable();
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            $table->string('status', 28);
            $table->json('validation_errors')->nullable();
            $table->string('matched_relation_id', 128)->nullable();
            $table->uuid('duplicate_of_id')->nullable();
            $table->text('result_reason')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->unique(['bank_import_id', 'row_number']);
            $table->index('payment_reference_normalized');
            $table->index(['branch_id', 'paid_at', 'status']);
            $table->index(['bank_import_id', 'status']);
        });

        Schema::table('bank_movements', function (Blueprint $table): void {
            $table->foreign('duplicate_of_id')->references('id')->on('bank_movements')->restrictOnDelete();
        });

        Schema::create('bank_folio_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('folio_scope', 160);
            $table->string('normalized_folio', 160);
            $table->foreignUuid('first_movement_id')->constrained('bank_movements')->restrictOnDelete();
            $table->timestampTz('reserved_at');
            $table->unique(['folio_scope', 'normalized_folio'], 'bank_folio_scope_unique');
            $table->unique('first_movement_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE bank_imports ADD CONSTRAINT bank_import_status_check CHECK (
                    status IN ('RECIBIDO', 'VALIDANDO', 'RECHAZADO', 'PROCESANDO', 'PROCESADO', 'FALLIDO')
                );
                ALTER TABLE bank_movements ADD CONSTRAINT bank_movement_status_check CHECK (
                    status IN ('PENDIENTE', 'INVALIDO', 'CONCILIADO', 'NO_CONCILIADO', 'DUPLICADO', 'REVISION_MANUAL', 'APLICADO_MANUALMENTE')
                );
                ALTER TABLE bank_movements ADD CONSTRAINT bank_movement_amount_check CHECK (
                    amount IS NULL OR amount > 0
                );
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_folio_reservations');
        Schema::dropIfExists('bank_movements');
        Schema::dropIfExists('bank_imports');
    }
};
