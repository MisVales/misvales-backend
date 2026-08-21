<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bank_file_imports', function (Blueprint $table): void {
            $table->string('original_name')->nullable()->after('private_path');
            $table->unsignedBigInteger('file_size')->nullable()->after('original_name');
            $table->timestampTz('processed_at')->nullable()->after('error');
        });

        Schema::table('bank_movements', function (Blueprint $table): void {
            $table->string('idempotency_bank_folio', 100)->nullable()->after('bank_folio');
            $table->foreignUuid('duplicate_of_id')->nullable()->after('idempotency_bank_folio')->constrained('bank_movements')->restrictOnDelete();
            $table->foreignUuid('distributor_id')->nullable()->after('relation_id')->constrained('distributors')->restrictOnDelete();
            $table->decimal('balance_before', 19, 4)->nullable()->after('distributor_id');
            $table->string('reconciliation_status', 32)->default('UNRECONCILED')->after('classification');
            $table->foreignUuid('reconciled_by')->nullable()->after('surplus_amount')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reconciled_at')->nullable()->after('reconciled_by');
        });

        DB::statement('UPDATE bank_movements SET idempotency_bank_folio = bank_folio WHERE idempotency_bank_folio IS NULL');

        Schema::table('bank_movements', function (Blueprint $table): void {
            $table->dropUnique('bank_movements_bank_folio_unique');
            $table->index('bank_folio');
            $table->unique('idempotency_bank_folio');
            $table->index(['reconciliation_status', 'created_at']);
        });

        Schema::table('payment_clarifications', function (Blueprint $table): void {
            $table->foreignUuid('created_by')->nullable()->after('evidence_media_id')->constrained('users')->restrictOnDelete();
        });

        Schema::table('manual_reconciliation_requests', function (Blueprint $table): void {
            $table->text('decision_reason')->nullable()->after('authorized_by');
            $table->timestampTz('decided_at')->nullable()->after('decision_reason');
        });

        DB::statement("ALTER TABLE bank_movements ADD CONSTRAINT bank_movements_reconciliation_status_check CHECK (reconciliation_status IN ('RECONCILED','UNRECONCILED','DUPLICATE','MANUAL_REQUESTED','MANUAL_AUTHORIZED','MANUALLY_RECONCILED','ERROR'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only: reverting would require deleting duplicate occurrence history
        // before restoring the former unique constraint on bank_folio.
    }
};
