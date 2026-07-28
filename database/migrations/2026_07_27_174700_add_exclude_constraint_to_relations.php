<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            
            DB::statement("
                ALTER TABLE relations 
                ADD CONSTRAINT relations_date_range_exclude 
                EXCLUDE USING gist (
                    distributor_id WITH =, 
                    daterange(early_payment_starts_at::date, due_at::date, '[]') WITH &&
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE relations DROP CONSTRAINT IF EXISTS relations_date_range_exclude');
        }
    }
};


