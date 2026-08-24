<?php

namespace App\Services\Conciliacion;

use App\Exceptions\ExcepcionConciliacion;
use App\Models\AuditLog;

final class ServicioDisponibilidadConciliacion
{
    public function asegurarCorteVencido(): string
    {
        $expiredAudit = AuditLog::query()
            ->where('entity_type', 'relation_process_run')
            ->where('event_name', 'PaymentDeadlineExpired')
            ->where('result', 'SUCCESS')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('audit_logs as completed')
                    ->whereColumn('completed.entity_id', 'audit_logs.entity_id')
                    ->where('completed.entity_type', 'relation_process_run')
                    ->where('completed.event_name', 'ForcePaymentDeadlineCompleted')
                    ->where('completed.result', 'SUCCESS');
            })
            ->oldest('created_at')
            ->first();

        if ($expiredAudit === null || $expiredAudit->entity_id === null) {
            throw new ExcepcionConciliacion(
                'RECONCILIATION_PERIOD_NOT_AVAILABLE',
                'La conciliación estará disponible cuando exista un corte vencido pendiente de conciliar.',
                409,
            );
        }

        return $expiredAudit->entity_id;
    }
}
