<?php

namespace App\Services\Operaciones;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Relacion\ServicioConfiguracionRelacion;
use App\Services\Relacion\ServicioGeneracionRelacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ServicioCorteManual
{
    public function __construct(
        private readonly ServicioGeneracionRelacion $generador,
        private readonly ServicioConfiguracionRelacion $configuracion,
    ) {}

    public function obtenerResumenCorteActual(?CarbonImmutable $referenceTime = null): array
    {
        $now = $referenceTime ?? CarbonImmutable::now('UTC');
        $projectedCutoff = $this->siguienteCorte($now);
        $config = $this->configuracion->periodoPago($now);
        $projectedDeadline = $projectedCutoff
            ->addDays($config['payment_deadline_days'])
            ->setTimeFromTimeString($config['payment_deadline_time']);
        $ultimoCierre = DB::table('relation_process_runs')
            ->where('status', 'COMPLETED')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('distributor_relations')
                    ->whereColumn('distributor_relations.process_run_id', 'relation_process_runs.id');
            })
            ->latest('cutoff_at')
            ->value('cutoff_at');

        $stats = DB::table('voucher_installments')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_installments.voucher_id')
            ->whereNotNull('voucher_installments.due_at')
            ->where('voucher_installments.due_at', '<=', $projectedDeadline)
            ->where('vouchers.status', 'CASHED')
            ->whereNotNull('vouchers.cashed_at')
            ->where('vouchers.cashed_at', '<=', $projectedCutoff)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('distributor_relation_items')
                    ->whereColumn('distributor_relation_items.voucher_installment_id', 'voucher_installments.id');
            })
            ->selectRaw('COUNT(DISTINCT vouchers.distributor_id) as distributors, COUNT(voucher_installments.id) as operations, SUM(voucher_installments.client_payment) as total')
            ->first();

        return [
            'has_open_cutoff' => true,
            'period' => [
                'start' => $ultimoCierre,
                'projected_end' => $projectedCutoff->utc()->toIso8601String(),
            ],
            'projected_status' => 'OPEN',
            'summary' => [
                'distributors' => (int) $stats->distributors,
                'operations' => (int) $stats->operations,
                'total' => (float) ($stats->total ?? 0),
            ],
            'payment_period' => $this->periodoPagoForzadoActual(),
        ];
    }

    public function forzarCorte(User $actor, ?string $motivo = null): array
    {
        // 3. Protección de concurrencia: Evitar dos ejecuciones simultáneas del cierre forzado.
        return Cache::lock('operation_force_cutoff', 10)->block(5, function () use ($actor, $motivo) {
            $now = CarbonImmutable::now('UTC');
            $cutoff = $this->siguienteCorte($now);

            $running = DB::table('relation_process_runs')
                ->where('status', 'RUNNING')
                ->exists();

            if ($running) {
                abort(422, 'Ya existe un proceso de cierre en ejecución.');
            }

            $resumen = $this->obtenerResumenCorteActual($now);

            $relationsGenerated = $this->generador->generar($cutoff);

            $runId = DB::table('relation_process_runs')
                ->where('status', 'COMPLETED')
                ->where('cutoff_at', $cutoff->utc())
                ->latest('created_at')
                ->value('id');

            $config = $this->configuracion->resolver($now);
            $deadline = $cutoff
                ->addDays($config['payment_deadline_days'])
                ->setTimeFromTimeString($config['payment_deadline_time']);

            // 2. Auditoría: Queda claro que se cerró un periodo proyectado, sin tratar 'ABIERTO'/'CERRADO' como tablas.
            AuditLog::create([
                'entity_type' => 'operation_cutoff',
                'event_name' => 'ForzarCorte',
                'entity_id' => $runId,
                'actor_id' => $actor->id,
                'previous_value' => [
                    'projected_status' => 'OPEN',
                    'summary' => $resumen['summary'],
                ],
                'new_value' => [
                    'projected_status' => 'CLOSED',
                    'motivo' => $motivo,
                    'simulated_cutoff_at' => $cutoff->utc()->toIso8601String(),
                    'payment_deadline_at' => $deadline->utc()->toIso8601String(),
                    'relations_generated' => $relationsGenerated,
                ],
                'result' => 'SUCCESS',
            ]);

            return [
                'success' => true,
                'process_run_id' => $runId,
                'projected_status' => 'CLOSED',
                'simulated_cutoff_at' => $cutoff->utc()->toIso8601String(),
                'payment_deadline_at' => $deadline->utc()->toIso8601String(),
                'relations_generated' => $relationsGenerated,
            ];
        });
    }

    private function siguienteCorte(CarbonImmutable $referenceTime): CarbonImmutable
    {
        $schedule = $this->configuracion->programacionCorte($referenceTime);
        $lastCompletedCutoff = DB::table('relation_process_runs')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('audit_logs')
                    ->whereColumn('audit_logs.entity_id', 'relation_process_runs.id')
                    ->where('audit_logs.entity_type', 'relation_process_run')
                    ->where('audit_logs.event_name', 'ForcePaymentDeadlineCompleted')
                    ->where('audit_logs.result', 'SUCCESS');
            })
            ->latest('cutoff_at')
            ->value('cutoff_at');
        if ($lastCompletedCutoff !== null) {
            return CarbonImmutable::parse($lastCompletedCutoff, $schedule['timezone'])
                ->setTimezone($schedule['timezone'])
                ->addMonthNoOverflow();
        }

        $localNow = $referenceTime->setTimezone($schedule['timezone']);
        [$hour, $minute] = array_map('intval', explode(':', $schedule['cutoff_time']));
        $candidate = $localNow->startOfDay()->day($schedule['cutoff_day'])->setTime($hour, $minute);

        return $candidate->lessThanOrEqualTo($localNow) ? $candidate->addMonthNoOverflow() : $candidate;
    }

    private function periodoPagoForzadoActual(): ?array
    {
        $runId = DB::table('relation_process_runs')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('audit_logs')
                    ->whereColumn('audit_logs.entity_id', 'relation_process_runs.id')
                    ->where('audit_logs.entity_type', 'operation_cutoff')
                    ->where('audit_logs.event_name', 'ForzarCorte')
                    ->where('audit_logs.result', 'SUCCESS');
            })
            ->latest('cutoff_at')
            ->value('id');
        $forcedCutoff = $runId === null ? null : AuditLog::query()
            ->where('entity_type', 'operation_cutoff')
            ->where('event_name', 'ForzarCorte')
            ->where('result', 'SUCCESS')
            ->where('entity_id', $runId)
            ->latest('created_at')
            ->first();

        if ($forcedCutoff === null || $forcedCutoff->entity_id === null) {
            return null;
        }

        $run = DB::table('relation_process_runs')->where('id', $forcedCutoff->entity_id)->where('status', 'COMPLETED')->first();
        if ($run === null) {
            return null;
        }

        $relations = DB::table('distributor_relations')->where('process_run_id', $run->id);
        if (! $relations->exists()) {
            return null;
        }
        $generatedStats = DB::table('distributor_relation_items')
            ->join('distributor_relations', 'distributor_relations.id', '=', 'distributor_relation_items.relation_id')
            ->where('distributor_relations.process_run_id', $run->id)
            ->selectRaw('COUNT(distributor_relation_items.id) as operations, SUM(distributor_relation_items.portfolio_amount) as total')
            ->first();
        $evaluation = AuditLog::query()
            ->where('entity_type', 'relation_process_run')
            ->where('entity_id', $run->id)
            ->where('event_name', 'ForcePaymentDeadlineCompleted')
            ->where('result', 'SUCCESS')
            ->latest()
            ->first();
        $deadlineReached = AuditLog::query()
            ->where('entity_type', 'relation_process_run')
            ->where('entity_id', $run->id)
            ->where('event_name', 'PaymentDeadlineReached')
            ->where('result', 'SUCCESS')
            ->latest()
            ->first();
        $deadlineExpired = AuditLog::query()
            ->where('entity_type', 'relation_process_run')
            ->where('entity_id', $run->id)
            ->where('event_name', 'PaymentDeadlineExpired')
            ->where('result', 'SUCCESS')
            ->latest()
            ->first();

        return [
            'process_run_id' => $run->id,
            'cutoff_at' => $forcedCutoff->new_value['simulated_cutoff_at'] ?? CarbonImmutable::parse($run->cutoff_at, config('app.timezone'))->utc()->toIso8601String(),
            'payment_deadline_at' => $forcedCutoff->new_value['payment_deadline_at'] ?? null,
            'relations' => $relations->count(),
            'summary' => [
                'distributors' => $relations->count(),
                'operations' => (int) $generatedStats->operations,
                'total' => (float) ($generatedStats->total ?? 0),
            ],
            'status' => $evaluation !== null ? 'COMPLETED' : ($deadlineExpired !== null ? 'EXPIRED' : ($deadlineReached !== null ? 'DEADLINE_REACHED' : 'OPEN')),
            'evaluated_at' => $evaluation?->new_value['evaluated_at'] ?? null,
            'overdue_evaluation_at' => $evaluation?->new_value['overdue_evaluation_at'] ?? $deadlineReached?->new_value['overdue_evaluation_at'] ?? null,
            'outcomes' => $evaluation?->new_value['outcomes'] ?? null,
        ];
    }
}
