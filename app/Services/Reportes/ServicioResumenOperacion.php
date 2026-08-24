<?php

namespace App\Services\Reportes;

use App\Models\User;
use App\Services\Alcance\AlcanceOperativo;
use App\Services\Alcance\ResolverAlcanceOperativo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ServicioResumenOperacion
{
    public function __construct(private readonly ResolverAlcanceOperativo $alcances) {}

    public function obtener(User $actor): array
    {
        $alcance = $this->alcances->resolver($actor);
        $hoyLocal = CarbonImmutable::now(config('relations.timezone'));
        $inicio = $hoyLocal->startOfDay()->utc();
        $fin = $hoyLocal->endOfDay()->utc();

        $valesHoy = DB::table('vouchers')->where('status', 'CASHED')->whereBetween('cashed_at', [$inicio, $fin]);
        $this->aplicar($valesHoy, $alcance, 'branch_id', ['cashed_by']);

        $valesPendientes = DB::table('vouchers')->whereIn('status', ['GENERATED', 'CASH_VALIDATION', 'RELEASED', 'CORRECTION_PENDING']);
        $this->aplicar($valesPendientes, $alcance, 'branch_id', ['released_by', 'cashed_by']);

        $movimientos = DB::table('bank_movements as movement')
            ->join('bank_file_imports as import', 'import.id', '=', 'movement.import_id');
        $this->aplicar($movimientos, $alcance, 'import.branch_id', ['import.uploaded_by']);

        $movimientosHoy = clone $movimientos;
        $movimientosHoy->whereBetween('movement.paid_at', [$inicio, $fin]);

        $pendientesConciliar = clone $movimientos;
        $pendientesConciliar->whereIn('movement.reconciliation_status', ['UNRECONCILED', 'ERROR', 'MANUAL_REQUESTED', 'MANUAL_AUTHORIZED']);

        $manuales = DB::table('manual_reconciliation_requests')->whereNotIn('status', ['EXECUTED', 'REJECTED']);
        $this->aplicar($manuales, $alcance, 'branch_id', ['requested_by', 'executed_by']);

        $aclaraciones = DB::table('payment_clarifications as clarification')
            ->join('distributor_relations as relation', 'relation.id', '=', 'clarification.relation_id')
            ->whereNotIn('clarification.status', ['RESOLVED', 'REJECTED']);
        $this->aplicar($aclaraciones, $alcance, 'relation.branch_id', ['clarification.created_by']);

        $devoluciones = DB::table('surplus_refund_requests')->where('status', 'AUTHORIZED');
        $this->aplicar($devoluciones, $alcance, 'branch_id', ['requested_by', 'executed_by']);

        return [
            'scope' => $alcance->tipo->value,
            'generated_at' => now()->toIso8601String(),
            'vouchers' => [
                'cashed_today' => (clone $valesHoy)->count(),
                'amount_today' => (string) ((clone $valesHoy)->sum('capital') ?? 0),
                'pending' => (clone $valesPendientes)->count(),
            ],
            'payments' => [
                'registered_today' => (clone $movimientosHoy)->count(),
                'amount_today' => (string) ((clone $movimientosHoy)->sum('movement.amount') ?? 0),
            ],
            'reconciliation' => [
                'pending' => (clone $pendientesConciliar)->count(),
                'manual_pending' => (clone $manuales)->count(),
                'reconciled_today' => (clone $movimientosHoy)->where('movement.reconciliation_status', 'RECONCILED')->count(),
                'reconciled_amount_today' => (string) ((clone $movimientosHoy)->where('movement.reconciliation_status', 'RECONCILED')->sum('movement.applied_amount') ?? 0),
                'surplus_today' => (clone $movimientosHoy)->where('movement.surplus_amount', '>', 0)->count(),
                'surplus_amount_today' => (string) ((clone $movimientosHoy)->sum('movement.surplus_amount') ?? 0),
            ],
            'clarifications' => [
                'pending' => (clone $aclaraciones)->count(),
                'authorized_refunds' => (clone $devoluciones)->count(),
            ],
        ];
    }

    /** @param list<string> $actorColumns */
    private function aplicar(Builder $query, AlcanceOperativo $alcance, string $branchColumn, array $actorColumns): void
    {
        if ($alcance->esGlobal()) {
            return;
        }
        if ($alcance->esSucursal()) {
            $query->whereIn($branchColumn, $alcance->branchIds);

            return;
        }

        $query->where(function (Builder $personal) use ($actorColumns, $alcance): void {
            foreach ($actorColumns as $index => $column) {
                $index === 0
                    ? $personal->where($column, $alcance->actorId)
                    : $personal->orWhere($column, $alcance->actorId);
            }
        });
    }
}
