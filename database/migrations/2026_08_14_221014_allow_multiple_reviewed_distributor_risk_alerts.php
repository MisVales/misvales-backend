<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('distributor_risk_alerts', function (Blueprint $table): void {
                $table->index('distributor_id', 'distributor_risk_alerts_distributor_id_index');
            });
        }

        Schema::table('distributor_risk_alerts', function (Blueprint $table): void {
            $table->dropUnique('distributor_risk_alerts_distributor_id_type_status_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('distributor_risk_alerts', function (Blueprint $table): void {
                $table->unsignedTinyInteger('open_alert_unique')->nullable()->storedAs("IF(status = 'OPEN', 1, NULL)");
                $table->unique(['distributor_id', 'type', 'open_alert_unique'], 'distributor_risk_alerts_one_open_unique');
            });
        } else {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX distributor_risk_alerts_one_open_unique
                ON distributor_risk_alerts (distributor_id, type)
                WHERE status = 'OPEN'
                SQL);
        }
    }

    public function down(): void
    {
        throw new LogicException(
            'Forward-only migration: restoring the former constraint could discard valid reviewed alert history.'
        );
    }
};
