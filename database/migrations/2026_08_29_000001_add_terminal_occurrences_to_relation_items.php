<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributor_relation_items', function (Blueprint $table): void {
            $table->uuid('voucher_installment_id')->nullable()->change();
            $table->string('occurrence_type', 24)->default('INSTALLMENT')->after('voucher_installment_id');
            $table->uuid('source_voucher_installment_id')->nullable()->after('occurrence_type');
            $table->uuid('previous_terminal_occurrence_id')->nullable()->after('source_voucher_installment_id');
            $table->unsignedInteger('terminal_sequence')->nullable()->after('previous_terminal_occurrence_id');
            $table->unique(['source_voucher_installment_id', 'terminal_sequence'], 'relation_items_terminal_source_sequence_unique');
            $table->unique('previous_terminal_occurrence_id', 'relation_items_previous_terminal_unique');
            $table->foreign('source_voucher_installment_id', 'relation_items_source_installment_fk')->references('id')->on('voucher_installments')->restrictOnDelete();
            $table->foreign('previous_terminal_occurrence_id', 'relation_items_previous_terminal_fk')->references('id')->on('distributor_relation_items')->restrictOnDelete();
        });

        DB::table('distributor_relation_items')->whereNull('source_voucher_installment_id')->update([
            'occurrence_type' => 'INSTALLMENT',
            'source_voucher_installment_id' => DB::raw('voucher_installment_id'),
        ]);

        DB::statement("ALTER TABLE distributor_relation_items ADD CONSTRAINT relation_items_occurrence_integrity_check CHECK ((occurrence_type = 'INSTALLMENT' AND voucher_installment_id IS NOT NULL AND (source_voucher_installment_id IS NULL OR source_voucher_installment_id = voucher_installment_id) AND previous_terminal_occurrence_id IS NULL AND terminal_sequence IS NULL) OR (occurrence_type = 'TERMINAL_OVERDUE' AND voucher_installment_id IS NULL AND source_voucher_installment_id IS NOT NULL AND terminal_sequence IS NOT NULL AND terminal_sequence > 0))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE distributor_relation_items DROP CONSTRAINT relation_items_occurrence_integrity_check');
        Schema::table('distributor_relation_items', function (Blueprint $table): void {
            $table->dropUnique('relation_items_terminal_source_sequence_unique');
            $table->dropUnique('relation_items_previous_terminal_unique');
            $table->dropForeign('relation_items_previous_terminal_fk');
            $table->dropForeign('relation_items_source_installment_fk');
            $table->dropColumn(['occurrence_type', 'source_voucher_installment_id', 'previous_terminal_occurrence_id', 'terminal_sequence']);
        });
        Schema::table('distributor_relation_items', fn (Blueprint $table) => $table->uuid('voucher_installment_id')->nullable(false)->change());
    }
};
