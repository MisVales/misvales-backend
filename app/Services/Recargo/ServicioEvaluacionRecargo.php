<?php

namespace App\Services\Recargo;

use App\Models\AuditLog;
use App\Models\RelacionDistribuidora;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServicioEvaluacionRecargo
{
    public function evaluar(CarbonImmutable $now): array
    {
        $tz = config('relations.timezone');
        $now = $now->setTimezone($tz);
        $result = ['applied' => 0, 'deferred' => 0];
        $relations = RelacionDistribuidora::where('balance', '>', 0)->where('payment_deadline_at', '<', $now->utc())->get();
        foreach ($relations as $relation) {
            $hasFile = DB::table('bank_file_imports')->where('branch_id', $relation->branch_id)->where('status', 'PROCESSED')->whereBetween('created_at', [$relation->payment_deadline_at, $relation->payment_deadline_at->addDay()->setTimezone($tz)->setTime(8, 0)->utc()])->exists();
            if (! $hasFile) {
                $result['deferred']++;
                AuditLog::firstOrCreate(['entity_type' => 'distributor_relation', 'entity_id' => $relation->id, 'event_name' => 'LateFeeDeferredMissingBankFile'], ['result' => 'DEFERRED', 'new_value' => ['evaluated_at' => $now->toIso8601String()]]);

                continue;
            }DB::transaction(function () use ($relation, $now, &$result) {
                $created = DB::table('relation_late_fees')->insertOrIgnore(['id' => (string) Str::uuid(), 'relation_id' => $relation->id, 'type' => 'LATE_FEE', 'amount' => config('surcharges.amount'), 'applied_at' => $now->utc(), 'configuration_snapshot' => json_encode(config('surcharges')), 'created_at' => now(), 'updated_at' => now()]);
                if ($created) {
                    $locked = RelacionDistribuidora::whereKey($relation->id)->lockForUpdate()->firstOrFail();
                    $locked->surcharge_total = bcadd($locked->surcharge_total, config('surcharges.amount'), 4);
                    $locked->balance = bcadd($locked->balance, config('surcharges.amount'), 4);
                    $locked->financial_status = 'OVERDUE';
                    $locked->save();
                    $result['applied']++;
                }
            });
        }

        return $result;
    }
}
