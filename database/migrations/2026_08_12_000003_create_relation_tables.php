<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relation_process_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampTz('cutoff_at');
            $table->string('status', 16);
            $table->unsignedInteger('attempt')->default(1);
            $table->jsonb('configuration_snapshot');
            $table->text('error')->nullable();
            $table->timestampsTz();
            $table->unique(['cutoff_at', 'attempt']);
        });
        Schema::create('distributor_relations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('process_run_id')->constrained('relation_process_runs')->restrictOnDelete();
            $table->foreignUuid('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->timestampTz('cutoff_at');
            $table->timestampTz('advance_period_start');
            $table->timestampTz('advance_period_end');
            $table->timestampTz('payment_deadline_at');
            $table->string('payment_reference', 40)->unique();
            $table->string('financial_status', 16)->default('PENDING');
            $table->string('review_status', 24)->default('NO_REVIEW');
            $table->decimal('portfolio_total', 19, 4);
            $table->decimal('misvales_total', 19, 4);
            $table->decimal('reconciled_total', 19, 4)->default(0);
            $table->decimal('surcharge_total', 19, 4)->default(0);
            $table->decimal('balance', 19, 4);
            $table->jsonb('header_snapshot');
            $table->jsonb('bank_snapshot');
            $table->timestampsTz();
            $table->unique(['distributor_id', 'cutoff_at']);
        });
        Schema::create('distributor_relation_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('relation_id')->constrained('distributor_relations')->restrictOnDelete();
            $table->foreignUuid('voucher_installment_id')->unique()->constrained('voucher_installments')->restrictOnDelete();
            $table->jsonb('snapshot');
            $table->decimal('portfolio_amount', 19, 4);
            $table->decimal('misvales_amount', 19, 4);
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE distributor_relations ADD CONSTRAINT distributor_relations_financial_status_check CHECK (financial_status IN ('PENDING','PARTIALLY_PAID','SETTLED','OVERDUE'))");
        DB::statement("ALTER TABLE distributor_relations ADD CONSTRAINT distributor_relations_review_status_check CHECK (review_status IN ('NO_REVIEW','CLARIFICATION_OPEN','MANUAL_REVIEW','RESOLVED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_relation_items');
        Schema::dropIfExists('distributor_relations');
        Schema::dropIfExists('relation_process_runs');
    }
};
