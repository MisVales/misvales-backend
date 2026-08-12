<?php

namespace App\Services\Puntos;

use App\Models\CuentaPuntos;
use App\Models\RelacionDistribuidora;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServicioPuntos
{
    public function clasificar(RelacionDistribuidora $relation): void
    {
        if (! in_array($relation->temporal_classification, ['EARLY', 'LATE'], true)) {
            return;
        }DB::transaction(function () use ($relation) {
            $account = CuentaPuntos::where('distributor_id', $relation->distributor_id)->lockForUpdate()->first() ?? CuentaPuntos::create(['distributor_id' => $relation->distributor_id]);
            $type = $relation->temporal_classification === 'EARLY' ? 'EARLY_GENERATION' : 'LATE_DISCOUNT';
            if (DB::table('point_movements')->where('relation_id', $relation->id)->where('type', $type)->exists()) {
                return;
            }
            $before = (int) ($account->balance ?? 0);
            $account->balance = $before;
            $account->reserved = (int) ($account->reserved ?? 0);
            $generated = 0;
            $discounted = 0;
            if ($type === 'EARLY_GENERATION') {
                $capital = $relation->partidas()->get()->reduce(fn (string $s, $i) => bcadd($s, (string) $i->snapshot['capital'], 4), '0.0000');
                $generated = (int) floor((float) bcdiv($capital, config('points.divisor'), 8)) * config('points.multiplier');
                $account->balance += $generated;
            } else {
                $discounted = (int) floor($account->balance * (float) config('points.late_discount_rate'));
                $account->balance = max(0, $account->balance - $discounted);
            }$account->lock_version++;
            $account->save();
            DB::table('point_movements')->insert(['id' => (string) Str::uuid(), 'point_account_id' => $account->id, 'relation_id' => $relation->id, 'type' => $type, 'balance_before' => $before, 'generated' => $generated, 'discounted' => $discounted, 'redeemed' => 0, 'balance_after' => $account->balance, 'reason' => $type === 'EARLY_GENERATION' ? 'Liquidación anticipada' : 'Liquidación fuera de tiempo', 'rule_snapshot' => json_encode(config('points')), 'rule_version' => config('points.rule_version'), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
