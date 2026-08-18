<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_file_imports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('private_path');
            $t->char('file_hash', 64)->unique();
            $t->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $t->string('status', 24);
            $t->unsignedInteger('row_count')->default(0);
            $t->jsonb('summary')->nullable();
            $t->text('error')->nullable();
            $t->timestampsTz();
        });
        Schema::create('bank_movements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('import_id')->constrained('bank_file_imports')->restrictOnDelete();
            $t->unsignedInteger('row_number');
            $t->jsonb('original_row');
            $t->string('payment_reference', 64);
            $t->decimal('amount', 19, 4);
            $t->timestampTz('paid_at');
            $t->string('bank_folio', 100)->unique();
            $t->text('concept');
            $t->string('classification', 24);
            $t->foreignUuid('relation_id')->nullable()->constrained('distributor_relations')->restrictOnDelete();
            $t->decimal('applied_amount', 19, 4)->default(0);
            $t->decimal('surplus_amount', 19, 4)->default(0);
            $t->jsonb('errors')->nullable();
            $t->timestampsTz();
            $t->unique(['import_id', 'row_number']);
        });
        Schema::create('payment_clarifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('folio', 32)->unique();
            $t->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $t->foreignUuid('relation_id')->constrained('distributor_relations')->restrictOnDelete();
            $t->foreignUuid('evidence_media_id')->constrained('media_files')->restrictOnDelete();
            $t->text('reason');
            $t->string('status', 24)->default('OPEN');
            $t->timestampsTz();
        });
        Schema::create('manual_reconciliation_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('bank_movement_id')->constrained('bank_movements')->restrictOnDelete();
            $t->foreignUuid('relation_id')->constrained('distributor_relations')->restrictOnDelete();
            $t->foreignUuid('clarification_id')->constrained('payment_clarifications')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $t->text('reason');
            $t->string('status', 24)->default('REQUESTED');
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->jsonb('before_snapshot')->nullable();
            $t->jsonb('after_snapshot')->nullable();
            $t->timestampTz('authorized_at')->nullable();
            $t->timestampTz('executed_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE bank_file_imports ADD CONSTRAINT bank_file_imports_status_check CHECK (status IN ('PROCESSED','REJECTED'))");
        DB::statement("ALTER TABLE bank_movements ADD CONSTRAINT bank_movements_classification_check CHECK (classification IN ('PARTIAL_PAYMENT','SETTLEMENT','SURPLUS','UNRECONCILED','DUPLICATE','ERROR'))");
        DB::statement("ALTER TABLE payment_clarifications ADD CONSTRAINT payment_clarifications_status_check CHECK (status IN ('OPEN','IN_REVIEW','RESOLVED','REJECTED'))");
        DB::statement("ALTER TABLE manual_reconciliation_requests ADD CONSTRAINT manual_reconciliation_status_check CHECK (status IN ('REQUESTED','AUTHORIZED','REJECTED','EXECUTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_reconciliation_requests');
        Schema::dropIfExists('payment_clarifications');
        Schema::dropIfExists('bank_movements');
        Schema::dropIfExists('bank_file_imports');
    }
};

