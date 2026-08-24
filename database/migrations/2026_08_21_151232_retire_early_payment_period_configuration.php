<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuration_definitions')
            ->where('key', 'EARLY_PAYMENT_PERIOD')
            ->update(['status' => 'INACTIVE', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('configuration_definitions')
            ->where('key', 'EARLY_PAYMENT_PERIOD')
            ->update(['status' => 'ACTIVE', 'updated_at' => now()]);
    }
};
