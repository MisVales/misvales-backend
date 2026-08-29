<?php

namespace App\Services\Conciliacion;

use App\Exceptions\ExcepcionConciliacion;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

final class ServicioDisponibilidadConciliacion
{
    public function asegurarCorteVencido(?string $processRunId = null, ?string $branchId = null): string
    {
        $expiredAudits = AuditLog::query()
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
            ->when($processRunId, fn ($query, string $id) => $query->where('entity_id', $id))
            ->oldest('created_at')
            ->get();
        $expiredAudit = $expiredAudits->first(
            fn (AuditLog $audit): bool => $branchId === null || $this->periodoPerteneceSucursal((string) $audit->entity_id, $branchId),
        );

        if ($expiredAudit === null || $expiredAudit->entity_id === null) {
            throw new ExcepcionConciliacion(
                'RECONCILIATION_PERIOD_NOT_AVAILABLE',
                'La conciliación estará disponible cuando exista un corte vencido pendiente de conciliar.',
                409,
            );
        }

        return $expiredAudit->entity_id;
    }

    public function periodosPendientes(?string $branchId = null): array
    {
        $audits = AuditLog::query()
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
            ->latest('created_at')
            ->get();

        return $audits->map(function (AuditLog $audit) use ($branchId): ?array {
            $relations = DB::table('distributor_relations')->where('process_run_id', $audit->entity_id);
            if ($branchId !== null) {
                $relations->where('branch_id', $branchId);
            }
            if ($branchId !== null && ! $this->periodoPerteneceSucursal((string) $audit->entity_id, $branchId)) {
                return null;
            }
            $run = DB::table('relation_process_runs')->where('id', $audit->entity_id)->first();
            $stats = (clone $relations)->selectRaw('COUNT(*) as relations, COUNT(DISTINCT distributor_id) as distributors, SUM(balance) as pending_total')->first();
            $sequence = DB::table('relation_process_runs as runs')
                ->where('runs.status', 'COMPLETED')
                ->where('runs.cutoff_at', '<=', $run?->cutoff_at)
                ->whereExists(function ($query) use ($branchId): void {
                    $query->selectRaw('1')->from('distributor_relations as sequence_relations')
                        ->whereColumn('sequence_relations.process_run_id', 'runs.id')
                        ->when($branchId !== null, fn ($branchQuery) => $branchQuery->where('sequence_relations.branch_id', $branchId));
                })->count();

            return [
                'process_run_id' => $audit->entity_id,
                'reconciliation_number' => $sequence,
                'cutoff_at' => $run?->cutoff_at,
                'payment_deadline_at' => $audit->new_value['expired_at'] ?? null,
                'relations' => (int) $stats->relations,
                'distributors' => (int) $stats->distributors,
                'pending_total' => (string) ($stats->pending_total ?? '0.0000'),
                'status' => 'PENDING_RECONCILIATION',
            ];
        })->filter()->values()->all();
    }

    private function periodoPerteneceSucursal(string $processRunId, string $branchId): bool
    {
        if (DB::table('distributor_relations')
            ->where('process_run_id', $processRunId)
            ->where('branch_id', $branchId)
            ->exists()) {
            return true;
        }

        $run = DB::table('relation_process_runs')->where('id', $processRunId)->first();
        if ($run === null) {
            return false;
        }
        $previousCutoff = DB::table('relation_process_runs')
            ->where('status', 'COMPLETED')
            ->where('cutoff_at', '<', $run->cutoff_at)
            ->latest('cutoff_at')
            ->value('cutoff_at');

        return DB::table('simulated_bank_transfers')
            ->where('branch_id', $branchId)
            ->where('paid_at', '<=', $run->cutoff_at)
            ->when($previousCutoff !== null, fn ($query) => $query->where('paid_at', '>', $previousCutoff))
            ->exists();
    }
}
