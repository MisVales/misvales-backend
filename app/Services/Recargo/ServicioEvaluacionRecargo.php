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
                $created = DB::table('relation_late_fees')->insertOrIgnore(['id' => (string) Str::uuid(), 'relation_id' => $relation->id, 'type' => 'LATE_FEE', 'amount' => $config['amount'], 'applied_at' => $now, 'configuration_snapshot' => json_encode($config), 'created_at' => now(), 'updated_at' => now()]);
                if ($created) {
                    $locked = RelacionDistribuidora::whereKey($relation->id)->lockForUpdate()->firstOrFail();
                    $locked->surcharge_total = bcadd($locked->surcharge_total, $config['amount'], 4);
                    $locked->balance = bcadd($locked->balance, $config['amount'], 4);
                    $locked->financial_status = 'OVERDUE';
                    $locked->save();
                    $result['applied']++;
                }
            });
        }

        return $result;
    }
}
