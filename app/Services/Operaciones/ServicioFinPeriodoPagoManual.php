<?php

namespace App\Services\Operaciones;

use App\Models\AuditLog;
use App\Models\Distribuidora;
use App\Models\RelacionDistribuidora;
use App\Models\User;
use App\Notifications\NotificacionEventoDominio;
use App\Services\Recargo\ServicioEvaluacionRecargo;
use App\Services\Riesgo\ServicioMorosidadDistribuidora;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ServicioFinPeriodoPagoManual
{
    public function __construct(
        private readonly ServicioEvaluacionRecargo $recargos,
        private readonly ServicioMorosidadDistribuidora $riesgo,
    ) {}

    public function forzar(User $actor, ?string $motivo = null, ?string $processRunId = null): array
    {
        return Cache::lock('operation_force_payment_deadline', 15)->block(5, function () use ($actor, $motivo, $processRunId): array {
            $run = $this->ultimoCorteForzado($processRunId);
            $completed = $this->evaluacionCompletada($run->id);
            if ($completed !== null) {
                return array_merge($completed->new_value, ['success' => true, 'replayed' => true]);
            }

            $relations = RelacionDistribuidora::query()
                ->where('process_run_id', $run->id)
                ->with(['distribuidora.usuario'])
                ->orderBy('id')
                ->get();
            $deadline = $this->fechaLimite($run->id, $relations, $run->cutoff_at);
            $overdueEvaluationAt = $deadline->addDay();
            $deadlineReached = AuditLog::query()
                ->where('entity_type', 'relation_process_run')
                ->where('entity_id', $run->id)
                ->where('event_name', 'PaymentDeadlineReached')
                ->where('result', 'SUCCESS')
                ->when($this->latestResetAt($run->id), fn ($query, $resetAt) => $query->where('created_at', '>', $resetAt))
                ->exists();

            if (! $deadlineReached) {
                AuditLog::create([
                    'entity_type' => 'relation_process_run',
                    'event_name' => 'PaymentDeadlineReached',
                    'entity_id' => $run->id,
                    'actor_id' => $actor->id,
                    'new_value' => [
                        'payment_deadline_at' => $deadline->toIso8601String(),
                        'overdue_evaluation_at' => $overdueEvaluationAt->toIso8601String(),
                        'motivo' => $motivo,
                    ],
                    'result' => 'SUCCESS',
                ]);

                return [
                    'success' => true,
                    'replayed' => false,
                    'status' => 'DEADLINE_REACHED',
                    'process_run_id' => $run->id,
                    'evaluated_at' => $deadline->toIso8601String(),
                    'overdue_evaluation_at' => $overdueEvaluationAt->toIso8601String(),
                    'message' => 'Fecha límite alcanzada. Los pagos realizados hoy se consideran puntuales; todavía no se marcaron atrasos.',
                ];
            }

            $deadlineExpired = AuditLog::query()
                ->where('entity_type', 'relation_process_run')
                ->where('entity_id', $run->id)
                ->where('event_name', 'PaymentDeadlineExpired')
                ->where('result', 'SUCCESS')
                ->when($this->latestResetAt($run->id), fn ($query, $resetAt) => $query->where('created_at', '>', $resetAt))
                ->exists();
            if (! $deadlineExpired) {
                AuditLog::create([
                    'entity_type' => 'relation_process_run',
                    'entity_id' => $run->id,
                    'event_name' => 'PaymentDeadlineExpired',
                    'actor_id' => $actor->id,
                    'new_value' => [
                        'expired_at' => $overdueEvaluationAt->toIso8601String(),
                        'motivo' => $motivo,
                    ],
                    'result' => 'SUCCESS',
                ]);
            }

            $missingBranches = $this->sucursalesSinArchivoFinal($relations, $run->id, CarbonImmutable::parse($run->created_at, config('app.timezone')));

            if ($missingBranches !== []) {
                AuditLog::create([
                    'entity_type' => 'relation_process_run',
                    'event_name' => 'ForcePaymentDeadlineDeferred',
                    'entity_id' => $run->id,
                    'actor_id' => $actor->id,
                    'new_value' => [
                        'evaluated_at' => $overdueEvaluationAt->toIso8601String(),
                        'missing_bank_file_branches' => $missingBranches,
                        'motivo' => $motivo,
                    ],
                    'result' => 'DEFERRED',
                ]);

                return [
                    'success' => false,
                    'replayed' => false,
                    'status' => 'DEFERRED',
                    'process_run_id' => $run->id,
                    'evaluated_at' => $overdueEvaluationAt->toIso8601String(),
                    'overdue_evaluation_at' => $overdueEvaluationAt->toIso8601String(),
                    'missing_bank_file_branches' => $missingBranches,
                    'message' => 'Falta procesar el archivo bancario final de una o más sucursales. No se asumieron pagos ni faltas de pago.',
                ];
            }

            $relationIds = $relations->pluck('id')->all();
            $lateFees = $this->recargos->evaluarRelacionesSimuladas(
                $overdueEvaluationAt,
                $relationIds,
                CarbonImmutable::parse($run->created_at, config('app.timezone')),
                $run->id,
            );

            $outcomes = ['settled' => 0, 'partially_paid' => 0, 'unpaid' => 0];
            $openReviews = 0;
            $notifications = 0;
            $distributorIds = [];

            foreach ($relations as $relation) {
                $relation->refresh();
                $outcome = $this->resultado($relation);
                $outcomes[$outcome]++;
                $openReviews += $relation->review_status === 'NO_REVIEW' || $relation->review_status === 'RESOLVED' ? 0 : 1;
                $distributorIds[] = $relation->distributor_id;

                $audit = AuditLog::firstOrCreate(
                    [
                        'entity_type' => 'distributor_relation',
                        'entity_id' => $relation->id,
                        'event_name' => 'PaymentDeadlineEvaluated',
                    ],
                    [
                        'actor_id' => $actor->id,
                        'branch_id' => $relation->branch_id,
                        'new_value' => [
                            'evaluated_at' => $overdueEvaluationAt->toIso8601String(),
                            'outcome' => $outcome,
                            'financial_status' => $relation->financial_status,
                            'review_status' => $relation->review_status,
                            'reconciled_total' => $relation->reconciled_total,
                            'balance' => $relation->balance,
                            'payments' => $relation->pagos()->count(),
                            'late_fee_applied' => DB::table('relation_late_fees')->where('relation_id', $relation->id)->whereNull('voided_at')->exists(),
                        ],
                        'result' => 'SUCCESS',
                    ],
                );

                if ($audit->wasRecentlyCreated && $relation->distribuidora?->usuario !== null) {
                    $relation->distribuidora->usuario->notify(new NotificacionEventoDominio([
                        'title' => 'Fecha límite evaluada',
                        'description' => $this->descripcionNotificacion($outcome, $relation),
                        'event_type' => 'PAYMENT_DEADLINE_EVALUATED',
                        'deep_link' => '/relaciones-pagos/relaciones',
                    ]));
                    $notifications++;
                }
            }

            $riskAlerts = 0;
            Distribuidora::query()
                ->with('usuario')
                ->whereIn('id', array_values(array_unique($distributorIds)))
                ->each(function (Distribuidora $distributor) use ($overdueEvaluationAt, &$riskAlerts): void {
                    if ($this->riesgo->evaluar($distributor, $overdueEvaluationAt) !== null) {
                        $riskAlerts++;
                    }
                });

            $result = [
                'status' => 'COMPLETED',
                'process_run_id' => $run->id,
                'evaluated_at' => $overdueEvaluationAt->toIso8601String(),
                'overdue_evaluation_at' => $overdueEvaluationAt->toIso8601String(),
                'relations_evaluated' => $relations->count(),
                'outcomes' => $outcomes,
                'late_fees' => $lateFees,
                'open_reviews' => $openReviews,
                'risk_alerts' => $riskAlerts,
                'notifications' => $notifications,
                'motivo' => $motivo,
            ];

            AuditLog::create([
                'entity_type' => 'relation_process_run',
                'event_name' => 'ForcePaymentDeadlineCompleted',
                'entity_id' => $run->id,
                'actor_id' => $actor->id,
                'new_value' => $result,
                'result' => 'SUCCESS',
            ]);

            return array_merge($result, ['success' => true, 'replayed' => false]);
        });
    }

    private function ultimoCorteForzado(?string $processRunId = null): object
    {
        $forcedCutoffs = AuditLog::query()
            ->where('entity_type', 'operation_cutoff')
            ->where('event_name', 'ForzarCorte')
            ->where('result', 'SUCCESS');
        if ($processRunId !== null) {
            $forcedCutoffs->where('entity_id', $processRunId);
        }
        $runId = (clone $forcedCutoffs)->whereNotExists(function ($query): void {
            $query->selectRaw('1')
                ->from('audit_logs as expired')
                ->whereColumn('expired.entity_id', 'audit_logs.entity_id')
                ->where('expired.entity_type', 'relation_process_run')
                ->where('expired.event_name', 'PaymentDeadlineExpired')
                ->where('expired.result', 'SUCCESS')
                ->whereNotExists(function ($reset): void {
                    $reset->selectRaw('1')->from('audit_logs as reset_log')
                        ->whereColumn('reset_log.entity_id', 'expired.entity_id')
                        ->where('reset_log.entity_type', 'relation_process_run')
                        ->where('reset_log.event_name', 'PaymentDeadlineEvaluationReset')
                        ->where('reset_log.result', 'SUCCESS')
                        ->whereColumn('reset_log.created_at', '>', 'expired.created_at');
                });
        })
            ->latest()
            ->value('entity_id');
        $runId ??= $processRunId === null ? (clone $forcedCutoffs)->latest()->value('entity_id') : $processRunId;

        if ($runId === null) {
            throw new RuntimeException('FORCED_CUTOFF_NOT_FOUND');
        }

        $run = DB::table('relation_process_runs')->where('id', $runId)->where('status', 'COMPLETED')->first();
        if ($run === null) {
            throw new RuntimeException('FORCED_CUTOFF_NOT_FOUND');
        }

        return $run;
    }

    private function evaluacionCompletada(string $runId): ?AuditLog
    {
        $resetAt = $this->latestResetAt($runId);

        return AuditLog::query()
            ->where('entity_type', 'relation_process_run')
            ->where('entity_id', $runId)
            ->where('event_name', 'ForcePaymentDeadlineCompleted')
            ->where('result', 'SUCCESS')
            ->when($resetAt, fn ($query) => $query->where('created_at', '>', $resetAt))
            ->latest()
            ->first();
    }

    private function latestResetAt(string $runId): mixed
    {
        return AuditLog::query()
            ->where('entity_type', 'relation_process_run')
            ->where('entity_id', $runId)
            ->where('event_name', 'PaymentDeadlineEvaluationReset')
            ->where('result', 'SUCCESS')
            ->max('created_at');
    }

    private function fechaLimite(string $runId, $relations, string $cutoffAt): CarbonImmutable
    {
        $forcedCutoff = AuditLog::query()
            ->where('entity_type', 'operation_cutoff')
            ->where('entity_id', $runId)
            ->where('event_name', 'ForzarCorte')
            ->latest()
            ->first();
        $deadline = $forcedCutoff?->new_value['payment_deadline_at'] ?? null;

        if ($deadline !== null) {
            return CarbonImmutable::parse($deadline)->utc();
        }

        $deadline = $relations->max('payment_deadline_at');
        if ($deadline !== null) {
            return CarbonImmutable::parse($deadline, config('app.timezone'))->utc();
        }

        return CarbonImmutable::parse($cutoffAt, config('app.timezone'))->utc();
    }

    private function sucursalesSinArchivoFinal($relations, string $processRunId, CarbonImmutable $importsSince): array
    {
        if ($relations->isEmpty()) {
            return [];
        }

        $branchIds = $relations->pluck('branch_id')->unique()->values();
        $withFile = DB::table('bank_file_imports')
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'PROCESSED')
            ->where(function ($query) use ($processRunId, $importsSince): void {
                $query->where('process_run_id', $processRunId)
                    ->orWhere(function ($legacy) use ($importsSince): void {
                        $legacy->whereNull('process_run_id')
                            ->where('created_at', '>=', $importsSince->setTimezone(config('app.timezone')));
                    });
            })
            ->where('created_at', '<=', CarbonImmutable::now(config('app.timezone')))
            ->pluck('branch_id')
            ->unique();

        return $branchIds->diff($withFile)->values()->all();
    }

    private function resultado(RelacionDistribuidora $relation): string
    {
        if (bccomp($relation->balance, '0', 4) === 0) {
            return 'settled';
        }

        return bccomp($relation->reconciled_total, '0', 4) > 0 ? 'partially_paid' : 'unpaid';
    }

    private function descripcionNotificacion(string $outcome, RelacionDistribuidora $relation): string
    {
        return match ($outcome) {
            'settled' => 'La relación quedó liquidada con los pagos conciliados registrados.',
            'partially_paid' => 'La relación conserva un saldo de $'.number_format((float) $relation->balance, 2).' después de sus abonos conciliados.',
            default => 'La relación terminó sin pagos conciliados y conserva un saldo de $'.number_format((float) $relation->balance, 2).'.',
        };
    }
}
