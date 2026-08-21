<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('bank_movements')
            ->where('classification', 'DUPLICATE')
            ->update(['reconciliation_status' => 'DUPLICATE']);

        DB::table('bank_movements')
            ->whereIn('id', DB::table('relation_payments')->whereNotNull('bank_movement_id')->select('bank_movement_id'))
            ->update([
                'reconciliation_status' => 'RECONCILED',
                'reconciled_at' => DB::raw('bank_movements.created_at'),
                'reconciled_by' => DB::raw('(SELECT uploaded_by FROM bank_file_imports WHERE bank_file_imports.id = bank_movements.import_id)'),
                'distributor_id' => DB::raw('(SELECT distributor_id FROM distributor_relations WHERE distributor_relations.id = bank_movements.relation_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only: the prior state did not distinguish historical reconciliation.
    }
};
