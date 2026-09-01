<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulated_bank_transfers', function (Blueprint $table): void {
            $table->foreignUuid('target_voucher_id')->nullable()->after('relation_id')->constrained('vouchers')->restrictOnDelete();
        });

        Schema::table('bank_movements', function (Blueprint $table): void {
            $table->foreignUuid('target_voucher_id')->nullable()->after('relation_id')->constrained('vouchers')->restrictOnDelete();
        });

        Schema::table('relation_payments', function (Blueprint $table): void {
            $table->foreignUuid('target_voucher_id')->nullable()->after('relation_id')->constrained('vouchers')->restrictOnDelete();
            $table->jsonb('trace_snapshot')->nullable()->after('line_recovered');
        });

        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->foreignUuid('voucher_id')->nullable()->after('relation_item_id')->constrained('vouchers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voucher_id');
        });

        Schema::table('relation_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('target_voucher_id');
            $table->dropColumn('trace_snapshot');
        });

        Schema::table('bank_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('target_voucher_id');
        });

        Schema::table('simulated_bank_transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('target_voucher_id');
        });
    }
};
