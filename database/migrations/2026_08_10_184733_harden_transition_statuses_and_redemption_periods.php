<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $estadosSolicitud = "'DRAFT', 'COORDINATOR_REVIEW', 'VERIFIER_ASSIGNED', 'PHYSICAL_VERIFICATION', 'COORDINATOR_CORRECTION', 'COORDINATOR_EVALUATION', 'MANAGER_AUTHORIZATION', 'TERMINATED_UNFAVORABLE', 'REJECTED', 'AUTHORIZED_PENDING_ACTIVATION', 'ACTIVE'";
        DB::statement('ALTER TABLE application_state_transitions DROP CONSTRAINT IF EXISTS application_state_transitions_from_status_check');
        DB::statement('ALTER TABLE application_state_transitions DROP CONSTRAINT IF EXISTS application_state_transitions_to_status_check');
        DB::statement("ALTER TABLE application_state_transitions ADD CONSTRAINT application_state_transitions_from_status_check CHECK (from_status IN ({$estadosSolicitud}))");
        DB::statement("ALTER TABLE application_state_transitions ADD CONSTRAINT application_state_transitions_to_status_check CHECK (to_status IN ({$estadosSolicitud}))");

        $estadosIncremento = "'REQUESTED', 'REJECTED_BY_COORDINATOR', 'PREAUTHORIZED', 'REJECTED_BY_MANAGER', 'AUTHORIZED_PARTIAL', 'AUTHORIZED_TOTAL', 'COMPLETED'";
        DB::statement('ALTER TABLE credit_increase_state_transitions DROP CONSTRAINT IF EXISTS credit_increase_state_transitions_from_status_check');
        DB::statement('ALTER TABLE credit_increase_state_transitions DROP CONSTRAINT IF EXISTS credit_increase_state_transitions_to_status_check');
        DB::statement("ALTER TABLE credit_increase_state_transitions ADD CONSTRAINT credit_increase_state_transitions_from_status_check CHECK (from_status IN ({$estadosIncremento}))");
        DB::statement("ALTER TABLE credit_increase_state_transitions ADD CONSTRAINT credit_increase_state_transitions_to_status_check CHECK (to_status IN ({$estadosIncremento}))");

        DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT IF EXISTS chk_rp_operational_point_value');
        DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_operational_point_value CHECK (status IN ('DRAFT', 'CANCELLED') OR (point_value IS NOT NULL AND point_value_configuration_version_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE application_state_transitions DROP CONSTRAINT IF EXISTS application_state_transitions_from_status_check');
        DB::statement('ALTER TABLE application_state_transitions DROP CONSTRAINT IF EXISTS application_state_transitions_to_status_check');
        DB::statement('ALTER TABLE credit_increase_state_transitions DROP CONSTRAINT IF EXISTS credit_increase_state_transitions_from_status_check');
        DB::statement('ALTER TABLE credit_increase_state_transitions DROP CONSTRAINT IF EXISTS credit_increase_state_transitions_to_status_check');
        DB::statement('ALTER TABLE redemption_periods DROP CONSTRAINT IF EXISTS chk_rp_operational_point_value');
        DB::statement("ALTER TABLE redemption_periods ADD CONSTRAINT chk_rp_operational_point_value CHECK (status = 'DRAFT' OR (point_value IS NOT NULL AND point_value_configuration_version_id IS NOT NULL))");
    }
};
