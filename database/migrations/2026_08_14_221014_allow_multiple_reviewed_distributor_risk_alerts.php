<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributor_risk_alerts', function (Blueprint $table): void {
            $table->dropUnique('distributor_risk_alerts_distributor_id_type_status_unique');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX distributor_risk_alerts_one_open_unique
            ON distributor_risk_alerts (distributor_id, type)
            WHERE status = 'OPEN'
            SQL);
    }

    public function down(): void
    {
        throw new LogicException(
            'Forward-only migration: restoring the former constraint could discard valid reviewed alert history.'
        );
    }
};
