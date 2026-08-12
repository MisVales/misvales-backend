<?php

namespace App\Services\Riesgo;

use App\Models\AlertaRiesgoDistribuidora;
use App\Models\Distribuidora;
use App\Models\RelacionDistribuidora;
use App\Models\SolicitudRetiroMorosidad;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ServicioMorosidadDistribuidora
{
    public function evaluar(Distribuidora $d): ?AlertaRiesgoDistribuidora
    {
        $overdue = RelacionDistribuidora::where('distributor_id', $d->id)->where('payment_deadline_at', '<', now())->latest('cutoff_at')->get();
        $consecutive = $overdue->takeUntil(fn ($r) => bccomp($r->balance, '0', 4) === 0)->filter(fn ($r) => bccomp($r->balance, '0', 4) > 0)->take(3);
        if ($consecutive->count() < 3) {
            if ($overdue->where('balance', '>', '0')->isEmpty()) {
                AlertaRiesgoDistribuidora::where('distributor_id', $d->id)->where('status', 'OPEN')->update(['status' => 'RESOLVED']);
            }

            return null;
        }$balance = $consecutive->reduce(fn (string $s, $r) => bcadd($s, $r->balance, 4), '0.0000');

        return AlertaRiesgoDistribuidora::firstOrCreate(['distributor_id' => $d->id, 'type' => 'THREE_CONSECUTIVE_DEFAULTS', 'status' => 'OPEN'], ['branch_id' => $d->branch_id, 'consecutive_defaults' => 3, 'relation_ids' => $consecutive->pluck('id')->all(), 'overdue_balance' => $balance]);
    }

    public function decidir(AlertaRiesgoDistribuidora $alert, User $actor, bool $apply, string $reason): void
    {
        abort_unless($actor->hasPermissionTo('delinquency.decide_global') || ($actor->hasPermissionTo('delinquency.decide_branch') && $actor->hasScopeForBranch($alert->branch_id)), 403);
        DB::transaction(function () use ($alert, $actor, $apply, $reason) {
            $a = AlertaRiesgoDistribuidora::whereKey($alert->id)->lockForUpdate()->firstOrFail();
            DB::table('distributor_delinquency_decisions')->insert(['id' => (string) Str::uuid(), 'distributor_id' => $a->distributor_id, 'risk_alert_id' => $a->id, 'decision' => $apply ? 'APPLY' : 'DO_NOT_APPLY', 'reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($apply) {
                DB::table('distributor_operational_blocks')->insertOrIgnore(['id' => (string) Str::uuid(), 'distributor_id' => $a->distributor_id, 'type' => 'DELINQUENCY', 'status' => 'ACTIVE', 'source_type' => 'RISK_ALERT', 'source_id' => $a->id, 'reason' => $reason, 'starts_at' => now(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }$a->update(['status' => 'REVIEWED']);
        });
    }

    public function solicitarRetiro(Distribuidora $d, User $coordinator, string $reason): SolicitudRetiroMorosidad
    {
        abort_unless($coordinator->hasPermissionTo('delinquency_removal.request_assigned'), 403);
        $assignment = $d->coordinadorVigente;
        abort_unless($assignment?->coordinator_id === $coordinator->id, 403);
        $overdue = RelacionDistribuidora::where('distributor_id', $d->id)->where('payment_deadline_at', '<', now())->where('balance', '>', 0)->exists();
        if ($overdue) {
            throw new RuntimeException('DISTRIBUTOR_NOT_REGULARIZED');
        }$block = DB::table('distributor_operational_blocks')->where('distributor_id', $d->id)->where('type', 'DELINQUENCY')->where('status', 'ACTIVE')->first();
        if (! $block) {
            throw new RuntimeException('DELINQUENCY_BLOCK_NOT_FOUND');
        }

        return SolicitudRetiroMorosidad::create(['distributor_id' => $d->id, 'block_id' => $block->id, 'branch_id' => $d->branch_id, 'reason' => $reason, 'requested_by' => $coordinator->id]);
    }

    public function decidirRetiro(SolicitudRetiroMorosidad $request, User $actor, bool $approve, string $reason): void
    {
        abort_unless($actor->hasPermissionTo('delinquency_removal.decide_global') || ($actor->hasPermissionTo('delinquency_removal.decide_branch') && $actor->hasScopeForBranch($request->branch_id)), 403);
        DB::transaction(function () use ($request, $actor, $approve, $reason) {
            $r = SolicitudRetiroMorosidad::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'REQUESTED') {
                throw new RuntimeException('REMOVAL_ALREADY_DECIDED');
            }$r->update(['status' => $approve ? 'AUTHORIZED' : 'REJECTED', 'decided_by' => $actor->id, 'decision_reason' => $reason, 'decided_at' => now()]);
            if ($approve) {
                DB::table('distributor_operational_blocks')->where('id', $r->block_id)->where('status', 'ACTIVE')->update(['status' => 'RELEASED', 'ends_at' => DB::raw("GREATEST(NOW(), starts_at + INTERVAL '1 second')"), 'updated_at' => now()]);
            }
        });
    }
}
