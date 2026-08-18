<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE relation_payments ALTER COLUMN bank_movement_id DROP NOT NULL');
        Schema::table('relation_payments', function (Blueprint $t): void {
            $t->string('source_type', 32)->default('BANK_MOVEMENT');
            $t->uuid('source_id')->nullable();
            $t->unique(['source_type', 'source_id']);
        });
        Schema::create('relation_late_fees', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('relation_id')->constrained('distributor_relations')->restrictOnDelete();
            $t->string('type', 24)->default('LATE_FEE');
            $t->decimal('amount', 19, 4);
            $t->timestampTz('applied_at');
            $t->jsonb('configuration_snapshot');
            $t->timestampsTz();
            $t->unique(['relation_id', 'type']);
        });
        Schema::create('distributor_surpluses', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $t->foreignUuid('bank_movement_id')->unique()->constrained('bank_movements')->restrictOnDelete();
            $t->decimal('original_amount', 19, 4);
            $t->decimal('available_amount', 19, 4);
            $t->decimal('reserved_amount', 19, 4)->default(0);
            $t->string('status', 32)->default('PENDING_DECISION');
            $t->timestampsTz();
        });
        Schema::create('surplus_applications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('surplus_id')->constrained('distributor_surpluses')->restrictOnDelete();
            $t->foreignUuid('relation_id')->constrained('distributor_relations')->restrictOnDelete();
            $t->decimal('amount', 19, 4);
            $t->timestampTz('applied_at');
            $t->unique(['surplus_id', 'relation_id']);
        });
        Schema::create('surplus_refund_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('surplus_id')->constrained('distributor_surpluses')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $t->decimal('amount', 19, 4);
            $t->string('status', 24)->default('REQUESTED');
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_reason')->nullable();
            $t->foreignUuid('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('execution_method')->nullable();
            $t->string('execution_reference')->nullable();
            $t->foreignUuid('evidence_media_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $t->timestampTz('executed_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE distributor_surpluses ADD CONSTRAINT distributor_surpluses_status_check CHECK (status IN ('PENDING_DECISION','CREDIT_BALANCE','REFUND_PENDING','REFUNDED','CONSUMED'))");
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE distributor_surpluses ADD CONSTRAINT distributor_surpluses_amounts_check CHECK (original_amount > 0 AND available_amount >= 0 AND reserved_amount >= 0 AND available_amount + reserved_amount <= original_amount)');
        }
        DB::statement("ALTER TABLE surplus_refund_requests ADD CONSTRAINT surplus_refunds_status_check CHECK (status IN ('REQUESTED','AUTHORIZED','REJECTED','EXECUTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('surplus_refund_requests');
        Schema::dropIfExists('surplus_applications');
        Schema::dropIfExists('distributor_surpluses');
        Schema::dropIfExists('relation_late_fees');
        Schema::table('relation_payments', function (Blueprint $t): void {
            $t->dropUnique(['source_type', 'source_id']);
            $t->dropColumn(['source_type', 'source_id']);
        });
        DB::statement('ALTER TABLE relation_payments ALTER COLUMN bank_movement_id SET NOT NULL');
    }
};
