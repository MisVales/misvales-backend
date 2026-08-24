<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('vouchers')
            ->where('status', 'CORRECTION_PENDING')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('voucher_modification_requests')
                    ->whereColumn('voucher_modification_requests.voucher_id', 'vouchers.id')
                    ->whereIn('voucher_modification_requests.status', ['REQUESTED', 'AUTHORIZED']);
            })
            ->update(['status' => 'GENERATED', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Esta reparación de estados es intencionalmente irreversible.
    }
};
