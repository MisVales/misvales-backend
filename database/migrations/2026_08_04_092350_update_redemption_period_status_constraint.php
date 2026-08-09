<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT chk_rp_status');
        DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_status CHECK (status IN ('DRAFT', 'SCHEDULED', 'OPEN', 'CLOSED', 'CANCELLED'));");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT chk_rp_status');
        DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_status CHECK (status IN ('DRAFT', 'PUBLISHED', 'CLOSED', 'CANCELLED'));");
    }
};
