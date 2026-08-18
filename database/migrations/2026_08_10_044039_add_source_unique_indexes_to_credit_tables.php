<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Partial unique index on credit_line_movements for source_type and source_id when source_type is not null
        DB::statement('CREATE UNIQUE INDEX idx_unique_source_credit_line_movements ON credit_line_movements (source_type, source_id)');

        // 2. Partial unique index on credit_usage_restrictions for source_type and source_id when source_type is not null
        DB::statement('CREATE UNIQUE INDEX idx_unique_source_credit_usage_restrictions ON credit_usage_restrictions (source_type, source_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_unique_source_credit_usage_restrictions');
        DB::statement('DROP INDEX IF EXISTS idx_unique_source_credit_line_movements');
    }
};
