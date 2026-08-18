<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT chk_rp_status');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_status CHECK (status IN ('DRAFT', 'SCHEDULED', 'OPEN', 'CLOSED', 'CANCELLED'));");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT chk_rp_status');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_status CHECK (status IN ('DRAFT', 'PUBLISHED', 'CLOSED', 'CANCELLED'));");
        }
    }
};
