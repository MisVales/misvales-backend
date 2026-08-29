<?php

namespace App\Services\Recargo;

use App\Models\AuditLog;
use App\Models\RelacionDistribuidora;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServicioEvaluacionRecargo
{
    public function __construct(private readonly ServicioConfiguracionRecargo $configuracion) {}

    public function evaluar(CarbonImmutable $now): array
    {
        return $this->evaluarInterno($now);
    }

    /** @param list<string> $relationIds */
    public function evaluarRelacionesSimuladas(CarbonImmutable $now, array $relationIds, CarbonImmutable $importsSince, ?string $processRunId = null): array
    {
        return $this->evaluarInterno($now, $relationIds, $importsSince, $processRunId);
    }

    /** @param list<string>|null $relationIds */
    private function evaluarInterno(CarbonImmutable $now, ?array $relationIds = null, ?CarbonImmutable $importsSince = null, ?string $processRunId = null): array
    {
        $config = $this->configuracion->resolver($now->utc());
        $tz = $config['timezone'];
        $now = $now->setTimezone($tz);
        $result = ['applied' => 0, 'deferred' => 0];
        $relations = RelacionDistribuidora::query()
            ->where('balance', '>', 0)
            ->where('payment_deadline_at', '<=', $now)
            ->when($relationIds !== null, fn ($query) => $query->whereIn('id', $relationIds))
            ->get();

        foreach ($relations as $relation) {
            [$deadlineHour, $deadlineMinute] = array_map('intval', explode(':', $config['bank_deadline_time']));
            $imports = DB::table('bank_file_imports')->where('branch_id', $relation->branch_id)->where('status', 'PROCESSED');
            $hasFile = $processRunId !== null
                ? (clone $imports)->where('process_run_id', $processRunId)->exists()
                    || (clone $imports)->whereNull('process_run_id')->where('created_at', '>=', $importsSince->setTimezone($tz))->exists()
                : ($importsSince === null
                ? $imports->whereBetween('created_at', [$relation->payment_deadline_at, $relation->payment_deadline_at->addDay()->setTimezone($tz)->setTime($deadlineHour, $deadlineMinute)->utc()])->exists()
                : $imports->where('created_at', '>=', $importsSince->setTimezone($tz))->where('created_at', '<=', CarbonImmutable::now($tz))->exists());

            if (! $hasFile) {
                $result['deferred']++;
                AuditLog::firstOrCreate(['entity_type' => 'distributor_relation', 'entity_id' => $relation->id, 'event_name' => 'LateFeeDeferredMissingBankFile'], ['result' => 'DEFERRED', 'new_value' => ['evaluated_at' => $now->toIso8601String()]]);

                continue;
            }

            DB::transaction(function () use ($relation, $now, $config, &$result) {
                // Calcular el recargo sumando late_fee_amount de cada parcialidad ligada a su producto
                $lateFeeTotal = '0.0000';
                $lateFeeDetail = [];

                $partidas = $relation->partidas()->with('installment.vale.product')->get();
                if ($partidas->isEmpty() && $relation->previous_relation_id) {
                    $prev = $relation->previousRelation;
                    while ($prev && $partidas->isEmpty()) {
                        $partidas = $prev->partidas()->with('installment.vale.product')->get();
                        $prev = $prev->previousRelation;
                    }
                }

                foreach ($partidas as $partida) {
                    $producto = $partida->installment?->vale?->product;
                    if ($producto && ! is_null($producto->late_fee_amount)) {
                        $lateFeeTotal = bcadd($lateFeeTotal, (string) $producto->late_fee_amount, 4);
                        $lateFeeDetail[] = [
                            'relation_item_id' => $partida->id,
                            'product_id' => $producto->id,
                            'late_fee_amount' => (string) $producto->late_fee_amount,
                        ];
                    }
                }

                if (bccomp($lateFeeTotal, '0', 4) <= 0) {
                    $lateFeeTotal = (string) ($config['amount'] ?? '300.0000');
                }

                $snapshot = array_merge(
                    $config,
                    ['late_fee_detail' => $lateFeeDetail, 'total_late_fee' => $lateFeeTotal],
                );

                $created = DB::table('relation_late_fees')->insertOrIgnore(['id' => (string) Str::uuid(), 'relation_id' => $relation->id, 'type' => 'LATE_FEE', 'amount' => $lateFeeTotal, 'applied_at' => $now, 'configuration_snapshot' => json_encode($snapshot), 'created_at' => now(), 'updated_at' => now()]);
                if ($created) {
                    $locked = RelacionDistribuidora::whereKey($relation->id)->lockForUpdate()->firstOrFail();
                    $profitForfeited = '0.0000';
                    foreach ($partidas as $partida) {
                        $snapshotItem = is_array($partida->snapshot) ? $partida->snapshot : json_decode($partida->snapshot, true);
                        $profit = (string) ($partida->installment?->vale?->distributor_profit_per_fortnight
                            ?? $snapshotItem['distributor_profit']
                            ?? '0.0000');

                        if ($partida->relation_id === $relation->id && empty($snapshotItem['distributor_profit_forfeited'])) {
                            $snapshotItem['distributor_profit_forfeited'] = true;
                            $snapshotItem['original_misvales_payment'] = (string) $partida->misvales_amount;
                            $newBalance = bcadd((string) $partida->balance, $profit, 4);
                            $newMisvales = bcadd((string) $partida->misvales_amount, $profit, 4);
                            DB::table('distributor_relation_items')->where('id', $partida->id)->update([
                                'balance' => $newBalance,
                                'misvales_amount' => $newMisvales,
                                'snapshot' => json_encode($snapshotItem),
                            ]);
                        }
                        $profitForfeited = bcadd($profitForfeited, $profit, 4);
                    }
                    $locked->surcharge_total = bcadd($locked->surcharge_total, $lateFeeTotal, 4);
                    $locked->misvales_total = bcadd($locked->misvales_total, $profitForfeited, 4);
                    $totalAddition = bcadd($lateFeeTotal, $profitForfeited, 4);
                    $locked->balance = bcadd($locked->balance, $totalAddition, 4);
                    $locked->financial_status = 'OVERDUE';
                    $locked->save();
                    $result['applied']++;
                }
            });
        }

        return $result;
    }
}
