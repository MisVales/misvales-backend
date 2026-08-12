<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CuentaPuntos;
use App\Models\RedemptionPeriod;
use App\Models\SolicitudCanjePuntos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PuntosController extends Controller
{
    public function account(Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('points.view_own') && $u->distribuidora, 403);
        $a = CuentaPuntos::firstOrCreate(['distributor_id' => $u->distribuidora->id]);
        $period = RedemptionPeriod::where('status', 'OPEN')->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();

        return response()->json(['data' => ['account' => $a, 'available' => $a->balance - $a->reserved, 'estimated_value' => $period ? bcmul((string) ($a->balance - $a->reserved), $period->point_value, 4) : null, 'period' => $period, 'movements' => DB::table('point_movements')->where('point_account_id', $a->id)->latest('occurred_at')->get()]]);
    }

    public function request(Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('points.redeem_own') && $u->distribuidora, 403);
        $d = $r->validate(['points' => ['required', 'integer', 'min:1']]);
        $period = RedemptionPeriod::where('status', 'OPEN')->where('starts_at', '<=', now())->where('ends_at', '>', now())->first();
        abort_unless($period, 409, 'REDEMPTION_CLOSED');

        return DB::transaction(function () use ($u, $d, $period) {
            $a = CuentaPuntos::where('distributor_id', $u->distribuidora->id)->lockForUpdate()->firstOrFail();
            abort_if($a->balance - $a->reserved < $d['points'], 409, 'POINTS_INSUFFICIENT');
            $a->reserved += $d['points'];
            $a->lock_version++;
            $a->save();
            $s = SolicitudCanjePuntos::create(['point_account_id' => $a->id, 'redemption_period_id' => $period->id, 'branch_id' => $u->distribuidora->branch_id, 'points' => $d['points'], 'point_value_snapshot' => $period->point_value, 'monetary_value' => bcmul((string) $d['points'], $period->point_value, 4), 'requested_by' => $u->id]);

            return response()->json(['data' => $s], 201);
        });
    }

    public function requests(Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('points.authorize_global') || $u->hasPermissionTo('points.authorize_branch') || $u->hasPermissionTo('points.deliver_branch'), 403);
        $q = SolicitudCanjePuntos::query()->latest();
        if (! $u->hasPermissionTo('points.authorize_global')) {
            $branches = $u->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $q->whereIn('branch_id', $branches);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function decide(SolicitudCanjePuntos $solicitud, Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('points.authorize_global') || ($u->hasPermissionTo('points.authorize_branch') && $u->hasScopeForBranch($solicitud->branch_id)), 403);
        abort_unless($solicitud->status === 'REQUESTED', 409);
        $d = $r->validate(['decision' => ['required', 'in:AUTHORIZE,REJECT'], 'reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($solicitud, $u, $d) {
            $s = SolicitudCanjePuntos::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();
            $s->update(['status' => $d['decision'] === 'AUTHORIZE' ? 'AUTHORIZED' : 'REJECTED', 'decided_by' => $u->id, 'decision_reason' => $d['reason']]);
            if ($d['decision'] === 'REJECT') {
                $a = CuentaPuntos::whereKey($s->point_account_id)->lockForUpdate()->firstOrFail();
                $a->reserved -= $s->points;
                $a->lock_version++;
                $a->save();
            }
        });

        return response()->json(['data' => $solicitud->fresh()]);
    }

    public function deliver(SolicitudCanjePuntos $solicitud, Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('points.deliver_branch') && $u->hasScopeForBranch($solicitud->branch_id), 403);
        abort_unless($solicitud->status === 'AUTHORIZED', 409);
        $d = $r->validate(['reference' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($solicitud, $u, $d) {
            $s = SolicitudCanjePuntos::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();
            $a = CuentaPuntos::whereKey($s->point_account_id)->lockForUpdate()->firstOrFail();
            $before = $a->balance;
            $a->balance -= $s->points;
            $a->reserved -= $s->points;
            $a->lock_version++;
            $a->save();
            DB::table('point_movements')->insert(['id' => (string) Str::uuid(), 'point_account_id' => $a->id, 'type' => 'REDEMPTION', 'balance_before' => $before, 'generated' => 0, 'discounted' => 0, 'redeemed' => $s->points, 'balance_after' => $a->balance, 'reason' => 'Canje entregado', 'rule_snapshot' => json_encode(['point_value' => $s->point_value_snapshot, 'period_id' => $s->redemption_period_id]), 'rule_version' => 'redemption-snapshot', 'performed_by' => $u->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $s->update(['status' => 'DELIVERED', 'delivered_by' => $u->id, 'delivery_reference' => $d['reference'], 'delivered_at' => now()]);
        });

        return response()->json(['data' => $solicitud->fresh()]);
    }
}
