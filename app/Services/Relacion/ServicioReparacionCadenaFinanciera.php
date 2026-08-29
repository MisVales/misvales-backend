<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Models\AuditLog;
use App\Models\RelacionDistribuidora;
use App\Services\Recargo\ServicioEvaluacionRecargo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ServicioReparacionCadenaFinanciera
{
    public function __construct(
        private readonly ServicioGeneracionRelacion $generador,
        private readonly ServicioEvaluacionRecargo $recargos,
    ) {}

    /** @return array{relations:int,late_fees:string,misvales_total:string,balance:string} */
    public function reparar(RelacionDistribuidora $current): array
    {
        return DB::transaction(function () use ($current): array {
            $current = RelacionDistribuidora::query()->lockForUpdate()->findOrFail($current->id);
            if ($current->rolled_forward_to_id !== null) {
                throw new RuntimeException('La reparación debe ejecutarse sobre la relación vigente de la cadena.');
            }
            if ($current->pagos()->exists()) {
                throw new RuntimeException('La relación vigente tiene pagos; se requiere conciliación individual antes de reconstruir la cadena.');
            }

            $chain = $this->chain($current);
            $relationIds = $chain->pluck('id');
            $now = now();
            DB::table('relation_late_fees')->whereIn('relation_id', $relationIds)->where('type', 'LATE_FEE')->update([
                'voided_at' => $now,
                'void_reason' => 'FINANCIAL_CHAIN_REPLAY',
                'updated_at' => $now,
            ]);

            $carry = $this->emptyComponents();
            $lateFees = '0.0000';
            foreach ($chain as $index => $relation) {
                $relation = RelacionDistribuidora::query()->lockForUpdate()->findOrFail($relation->id);
                if ($relation->pagos()->exists()) {
                    throw new RuntimeException("La relación {$relation->id} tiene pagos; no puede repararse automáticamente.");
                }
                $next = $chain->get($index + 1);
                $carriedBalance = $this->sum($carry);
                $newMisvales = $relation->partidas()->get()->reduce(
                    fn (string $sum, $item): string => bcadd($sum, (string) $item->misvales_amount, 4),
                    '0.0000',
                );
                $newPortfolio = $relation->partidas()->get()->reduce(
                    fn (string $sum, $item): string => bcadd($sum, (string) $item->portfolio_amount, 4),
                    '0.0000',
                );
                $generatedTotal = bcadd($carriedBalance, $newMisvales, 4);
                $relation->forceFill([
                    'portfolio_total' => bcadd($carriedBalance, $newPortfolio, 4),
                    'misvales_total' => $generatedTotal,
                    'surcharge_total' => $carry['surcharge'],
                    'carried_balance' => $carriedBalance,
                    'carried_surcharge' => $carry['surcharge'],
                    'carried_interest' => $carry['interest'],
                    'carried_insurance' => $carry['insurance'],
                    'carried_commission' => $carry['commission'],
                    'carried_capital' => $carry['capital'],
                    'reconciled_total' => '0.0000',
                    'balance' => $generatedTotal,
                    'financial_status' => 'PENDING',
                    'settled_at' => null,
                    'rolled_forward_at' => null,
                    'rolled_forward_amount' => '0.0000',
                    'rolled_forward_to_id' => null,
                ])->save();

                if ($next === null) {
                    continue;
                }

                $appliedAt = CarbonImmutable::parse((string) $next->getRawOriginal('cutoff_at'), 'UTC');
                $this->recargos->aplicarAntesDeRollover($relation, $appliedAt);
                $relation->refresh();
                $ownFee = bcsub((string) $relation->surcharge_total, (string) $relation->carried_surcharge, 4);
                $lateFees = bcadd($lateFees, $ownFee, 4);
                $carry = $this->generador->saldoPendientePorComponente($relation);
                $transferred = $this->sum($carry);
                $relation->forceFill([
                    'financial_status' => 'ROLLED_FORWARD',
                    'balance' => '0.0000',
                    'rolled_forward_to_id' => $next->id,
                    'rolled_forward_at' => $appliedAt,
                    'rolled_forward_amount' => $transferred,
                ])->save();
            }

            $current->refresh();
            AuditLog::create([
                'entity_type' => 'distributor_relation',
                'entity_id' => $current->id,
                'event_name' => 'FinancialChainRepaired',
                'result' => 'SUCCESS',
                'previous_value' => ['reason' => 'DEFERRED_DEADLINES_ROLLED_FORWARD_WITHOUT_CANONICAL_CYCLE_CHARGES'],
                'new_value' => [
                    'relations' => $chain->count(),
                    'late_fees' => $lateFees,
                    'misvales_total' => $current->misvales_total,
                    'balance' => $current->balance,
                ],
            ]);
            AuditLog::create([
                'entity_type' => 'relation_process_run',
                'entity_id' => $current->process_run_id,
                'event_name' => 'PaymentDeadlineEvaluationReset',
                'result' => 'SUCCESS',
                'new_value' => ['relation_id' => $current->id, 'reason' => 'FINANCIAL_CHAIN_REPAIRED'],
            ]);

            return [
                'relations' => $chain->count(),
                'late_fees' => $lateFees,
                'misvales_total' => (string) $current->misvales_total,
                'balance' => (string) $current->balance,
            ];
        });
    }

    /** @return Collection<int,RelacionDistribuidora> */
    private function chain(RelacionDistribuidora $current): Collection
    {
        $chain = collect();
        for ($relation = $current; $relation !== null; $relation = $relation->anterior()->first()) {
            $chain->prepend($relation);
        }

        return $chain->values();
    }

    /** @return array{surcharge:string,interest:string,insurance:string,commission:string,capital:string} */
    private function emptyComponents(): array
    {
        return ['surcharge' => '0.0000', 'interest' => '0.0000', 'insurance' => '0.0000', 'commission' => '0.0000', 'capital' => '0.0000'];
    }

    /** @param array<string,string> $components */
    private function sum(array $components): string
    {
        return array_reduce($components, static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
    }
}
