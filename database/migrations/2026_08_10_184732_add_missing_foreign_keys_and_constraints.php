<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' && DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('La alineaciÃ³n de esquema requiere PostgreSQL.');
        }

        $this->abortarSiConsultaDevuelveIds(
            "SELECT id FROM distributors WHERE status = 'ACTIVE' AND (activated_at IS NULL OR activated_by IS NULL) LIMIT 20",
            'Distribuidoras ACTIVE sin evidencia de activaciÃ³n',
        );
        $this->abortarSiConsultaDevuelveIds(
            "SELECT id FROM verification_visits WHERE status NOT IN ('ASSIGNED','IN_PROGRESS','COMPLETED') OR (result IS NOT NULL AND result NOT IN ('FAVORABLE','UNFAVORABLE')) OR (status = 'COMPLETED' AND (result IS NULL OR completed_at IS NULL)) OR (status <> 'COMPLETED' AND (result IS NOT NULL OR completed_at IS NOT NULL)) LIMIT 20",
            'Visitas con estado/resultado incompatible',
        );
        $this->abortarSiConsultaDevuelveIds(
            "SELECT id FROM application_evaluations WHERE result NOT IN ('COMPLIES','DOES_NOT_COMPLY') LIMIT 20",
            'Evaluaciones con resultado fuera del dominio',
        );
        $this->abortarSiConsultaDevuelveIds(
            "SELECT id FROM application_authorizations WHERE decision NOT IN ('APPROVED','REJECTED') OR (decision = 'APPROVED' AND (initial_credit_line_amount IS NULL OR initial_credit_line_amount <= 0)) OR (decision = 'REJECTED' AND initial_credit_line_amount IS NOT NULL) LIMIT 20",
            'Autorizaciones con decisiÃ³n/importe incompatible',
        );
        $this->abortarSiConsultaDevuelveIds(
            'SELECT id FROM credit_line_movements WHERE amount <= 0 OR total_authorized_before <= 0 OR total_authorized_after <= 0 OR used_balance_before < 0 OR used_balance_before > total_authorized_before OR used_balance_after < 0 OR used_balance_after > total_authorized_after LIMIT 20',
            'Movimientos de lÃ­nea con snapshots imposibles',
        );
        $this->abortarSiConsultaDevuelveIds(
            "SELECT id FROM credit_usage_restrictions WHERE status NOT IN ('ACTIVE','RESERVED','CONSUMED','CANCELLED') OR NOT (
                (status = 'ACTIVE' AND reserved_voucher_id IS NULL AND reserved_at IS NULL AND consumed_at IS NULL AND cancelled_at IS NULL)
                OR (status = 'RESERVED' AND reserved_voucher_id IS NOT NULL AND reserved_at IS NOT NULL AND consumed_at IS NULL AND cancelled_at IS NULL)
                OR (status = 'CONSUMED' AND reserved_voucher_id IS NOT NULL AND reserved_at IS NOT NULL AND consumed_at IS NOT NULL AND cancelled_at IS NULL)
                OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND consumed_at IS NULL AND ((reserved_voucher_id IS NULL AND reserved_at IS NULL) OR (reserved_voucher_id IS NOT NULL AND reserved_at IS NOT NULL)))
            ) LIMIT 20",
            'Restricciones de crÃ©dito con ciclo incompatible',
        );
        $this->abortarSiConsultaDevuelveIds(
            'SELECT id FROM credit_increase_requests WHERE requested_amount <= 0 OR recommended_amount <= 0 OR authorized_amount <= 0 OR authorized_amount > requested_amount OR line_total_at_request <= 0 OR used_balance_at_request < 0 OR used_balance_at_request > line_total_at_request OR available_balance_at_request < 0 OR available_balance_at_request <> line_total_at_request - used_balance_at_request LIMIT 20',
            'Solicitudes de incremento con importes/snapshots incompatibles',
        );
        $this->abortarSiConsultaDevuelveIds(
            "SELECT distributor_id AS id FROM coordinator_distributor_assignments WHERE status = 'ACTIVE' AND valid_to IS NULL GROUP BY distributor_id HAVING COUNT(*) > 1 LIMIT 20",
            'Distribuidoras con mÃ¡s de una asignaciÃ³n vigente',
        );

        $this->recrearFk('verification_visits', 'application_id', 'distributor_applications');
        $this->recrearFk('application_corrections', 'application_id', 'distributor_applications');
        $this->recrearFk('application_evaluations', 'application_id', 'distributor_applications');
        $this->recrearFk('application_authorizations', 'application_id', 'distributor_applications');
        $this->recrearFk('coordinator_distributor_assignments', 'distributor_id', 'distributors');
        $this->recrearFk('redemption_periods', 'point_value_configuration_version_id', 'configuration_versions');

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE application_evaluations DROP CONSTRAINT IF EXISTS application_evaluations_application_id_unique');
        }
        DB::statement('DROP INDEX IF EXISTS application_evaluations_application_id_unique');
        DB::statement('CREATE INDEX IF NOT EXISTS application_evaluations_application_id_evaluated_at_index ON application_evaluations (application_id, evaluated_at)');

        $this->recrearCheck('distributors', 'distributors_activation_check', "status <> 'ACTIVE' OR (activated_at IS NOT NULL AND activated_by IS NOT NULL)");
        $this->recrearCheck('verification_visits', 'verification_visits_status_check', "status IN ('ASSIGNED','IN_PROGRESS','COMPLETED')");
        $this->recrearCheck('verification_visits', 'verification_visits_result_check', "result IS NULL OR result IN ('FAVORABLE','UNFAVORABLE')");
        $this->recrearCheck('verification_visits', 'verification_visits_state_check', "(status = 'COMPLETED' AND result IS NOT NULL AND completed_at IS NOT NULL) OR (status <> 'COMPLETED' AND result IS NULL AND completed_at IS NULL)");
        $this->recrearCheck('application_evaluations', 'application_evaluations_result_check', "result IN ('COMPLIES','DOES_NOT_COMPLY')");
        $this->recrearCheck('application_authorizations', 'application_authorizations_decision_check', "decision IN ('APPROVED','REJECTED')");
        $this->recrearCheck('application_authorizations', 'application_authorizations_amount_check', "(decision = 'APPROVED' AND initial_credit_line_amount > 0) OR (decision = 'REJECTED' AND initial_credit_line_amount IS NULL)");
        $this->recrearCheck('credit_line_movements', 'credit_line_movements_balances_check', 'total_authorized_before > 0 AND total_authorized_after > 0 AND used_balance_before >= 0 AND used_balance_before <= total_authorized_before AND used_balance_after >= 0 AND used_balance_after <= total_authorized_after');
        $this->recrearCheck('credit_increase_requests', 'credit_increase_requests_amounts_check', 'requested_amount > 0 AND (recommended_amount IS NULL OR recommended_amount > 0) AND (authorized_amount IS NULL OR (authorized_amount > 0 AND authorized_amount <= requested_amount))');
        $this->recrearCheck('credit_increase_requests', 'credit_increase_requests_snapshot_check', 'line_total_at_request > 0 AND used_balance_at_request >= 0 AND used_balance_at_request <= line_total_at_request AND available_balance_at_request >= 0 AND available_balance_at_request = line_total_at_request - used_balance_at_request');
        $this->recrearCheck('credit_increase_requests', 'credit_increase_requests_status_check', "status IN ('REQUESTED','REJECTED_BY_COORDINATOR','PREAUTHORIZED','REJECTED_BY_MANAGER','AUTHORIZED_PARTIAL','AUTHORIZED_TOTAL','COMPLETED')");
        $this->recrearCheck('credit_increase_requests', 'credit_increase_requests_manager_decision_check', "manager_decision IS NULL OR manager_decision IN ('APPROVE_REQUESTED','APPROVE_LOWER','REJECT')");

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS coordinator_distributor_active_distributor_unique ON coordinator_distributor_assignments (distributor_id) WHERE status = 'ACTIVE' AND valid_to IS NULL");
    }

    public function down(): void
    {
        throw new RuntimeException('Las garantÃ­as de integridad agregadas son forward-only.');
    }

    private function recrearFk(string $tabla, string $columna, string $referenciada): void
    {
        $this->abortarSiConsultaDevuelveIds(
            "SELECT child.{$columna} AS id FROM {$tabla} child LEFT JOIN {$referenciada} parent ON parent.id = child.{$columna} WHERE child.{$columna} IS NOT NULL AND parent.id IS NULL LIMIT 20",
            "{$tabla}.{$columna} contiene referencias huÃ©rfanas",
        );
        $nombre = "{$tabla}_{$columna}_foreign";
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$nombre}");
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} FOREIGN KEY ({$columna}) REFERENCES {$referenciada}(id) ON DELETE RESTRICT");
        }
    }

    private function recrearCheck(string $tabla, string $nombre, string $expresion): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$nombre}");
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} CHECK ({$expresion})");
        }
    }

    private function abortarSiConsultaDevuelveIds(string $sql, string $mensaje): void
    {
        $ids = collect(DB::select($sql))->pluck('id');
        if ($ids->isNotEmpty()) {
            throw new RuntimeException($mensaje.'. IDs: '.$ids->implode(', '));
        }
    }
};
