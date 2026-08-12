<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_modification_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('voucher_id')->constrained('vouchers')->restrictOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('requested_fields');
            $table->text('reason');
            $table->string('status', 24)->default('REQUESTED');
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('token_hash', 64)->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('token_used_at')->nullable();
            $table->jsonb('changes_before')->nullable();
            $table->jsonb('changes_after')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['voucher_id', 'status']);
        });
        DB::statement("ALTER TABLE voucher_modification_requests ADD CONSTRAINT voucher_modification_status_check CHECK (status IN ('REQUESTED', 'AUTHORIZED', 'REJECTED', 'APPLIED', 'EXPIRED'))");
        DB::statement('ALTER TABLE voucher_modification_requests ADD CONSTRAINT voucher_modification_lock_check CHECK (lock_version >= 1)');
        DB::statement("ALTER TABLE voucher_modification_requests ADD CONSTRAINT voucher_modification_token_check CHECK ((status = 'AUTHORIZED' AND token_hash IS NOT NULL AND token_expires_at IS NOT NULL AND token_used_at IS NULL) OR status <> 'AUTHORIZED')");

        Schema::create('voucher_cash_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('voucher_id')->unique()->constrained('vouchers')->restrictOnDelete();
            $table->string('bank_transaction_number', 128)->unique();
            $table->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->timestampTz('cashed_at');
            $table->timestampsTz();
        });

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->foreignUuid('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->foreignUuid('cashed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cashed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn('released_at');
            $table->dropConstrainedForeignId('cashed_by');
            $table->dropColumn('cashed_at');
        });
        Schema::dropIfExists('voucher_cash_transactions');
        Schema::dropIfExists('voucher_modification_requests');
    }
};
