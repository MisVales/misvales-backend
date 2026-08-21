<?php

namespace App\Services\Operaciones;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Relacion\ServicioGeneracionRelacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

final class ServicioCorteManual
{
    public function __construct(
        private readonly ServicioGeneracionRelacion $generador
    ) {}

    public function obtenerResumenCorteActual(?CarbonImmutable $referenceTime = null): array
    {
        $now = $referenceTime ?? CarbonImmutable::now('UTC');
        
        $ultimoCierre = DB::table('relation_process_runs')
            ->where('status', 'COMPLETED')
            ->orderByDesc('cutoff_at')
            ->value('cutoff_at');
            
        $stats = DB::table('voucher_installments')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_installments.voucher_id')
            ->whereNotNull('voucher_installments.due_at')
            ->where('voucher_installments.due_at', '<=', $now)
            ->where('vouchers.status', 'CASHED')
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
                'projected_end' => $now->toIso8601String(),
            ],
            'projected_status' => 'OPEN',
            'summary' => [
                'distributors' => (int) $stats->distributors,
                'operations' => (int) $stats->operations,
                'total' => (float) ($stats->total ?? 0),
            ]
        ];
    }

    public function forzarCorte(User $actor, ?string $motivo = null): array
    {
        // 3. Protección de concurrencia: Evitar dos ejecuciones simultáneas del cierre forzado.
        return Cache::lock('operation_force_cutoff', 10)->block(5, function () use ($actor, $motivo) {
            // 1. Único cutoff_at: Se utiliza exactamente la misma instancia para todo el flujo.
            $now = CarbonImmutable::now('UTC');
            
            $running = DB::table('relation_process_runs')
                ->where('status', 'RUNNING')
                ->exists();
                
            if ($running) {
                abort(422, 'Ya existe un proceso de cierre en ejecución.');
            }

            $resumen = $this->obtenerResumenCorteActual($now);

            // Reutilización de lógica existente en ServicioGeneracionRelacion
            $this->generador->generar($now);
            
            $runId = DB::table('relation_process_runs')
                ->where('status', 'COMPLETED')
                ->orderByDesc('created_at')
                ->value('id');

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
                    'projected_end' => $now->toIso8601String(),
                ],
                'result' => 'SUCCESS',
            ]);

            return [
                'success' => true,
                'process_run_id' => $runId,
                'projected_status' => 'CLOSED'
            ];
        });
    }
}
