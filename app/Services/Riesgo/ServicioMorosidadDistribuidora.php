<?php

namespace App\Services\Riesgo;

use App\Models\AlertaRiesgoDistribuidora;
use App\Models\Distribuidora;
use App\Models\RelacionDistribuidora;
use App\Models\SolicitudRetiroMorosidad;
use App\Models\User;
use App\Notifications\NotificacionEventoDominio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

final class ServicioMorosidadDistribuidora
{
    public function evaluar(Distribuidora $d, ?CarbonImmutable $asOf = null): ?AlertaRiesgoDistribuidora
    {
        $evaluationTime = ($asOf ?? CarbonImmutable::now('UTC'))->setTimezone(config('relations.timezone'));
        $overdue = RelacionDistribuidora::where('distributor_id', $d->id)->where('payment_deadline_at', '<=', $evaluationTime)->latest('cutoff_at')->get();
        $consecutive = $overdue->takeUntil(fn ($r) => bccomp($r->balance, '0', 4) === 0)->filter(fn ($r) => bccomp($r->balance, '0', 4) > 0);

        $consecutiveCount = $consecutive->count();

        $isMorosa = DB::table('distributor_operational_blocks')
            ->where('distributor_id', $d->id)
            ->where('type', 'DELINQUENCY')
            ->where('status', 'ACTIVE')
            ->exists();

        if ($consecutiveCount >= 1 && ! $isMorosa) {
            $balance = $consecutive->reduce(fn (string $s, $r) => bcadd($s, $r->balance, 4), '0.0000');
            $cacheKey = "notified_delinquency_{$d->id}_{$consecutiveCount}";

            if (! Cache::has($cacheKey)) {
                $d->usuario->notify(new NotificacionEventoDominio([
                    'title' => "{$consecutiveCount} faltas de pago consecutivas",
                    'description' => 'Tienes un adeudo pendiente de $'.number_format((float) $balance, 2).'. Recuerda que al llegar a 3 faltas tu cuenta puede ser bloqueada.',
                    'deep_link' => '/cartera',
                ]));
                Cache::put($cacheKey, true, now()->addDays(7));
            }
        }

        if ($consecutiveCount < 3) {
            if ($overdue->where('balance', '>', '0')->isEmpty()) {
                AlertaRiesgoDistribuidora::where('distributor_id', $d->id)->where('status', 'OPEN')->update(['status' => 'RESOLVED']);
                Cache::forget("notified_delinquency_{$d->id}_1");
                Cache::forget("notified_delinquency_{$d->id}_2");
            }

            return null;
        }

        $balance = $consecutive->reduce(fn (string $s, $r) => bcadd($s, $r->balance, 4), '0.0000');

        $alerta = AlertaRiesgoDistribuidora::firstOrCreate(
            ['distributor_id' => $d->id, 'type' => 'THREE_CONSECUTIVE_DEFAULTS', 'status' => 'OPEN'],
            ['branch_id' => $d->branch_id, 'consecutive_defaults' => 3, 'relation_ids' => $consecutive->pluck('id')->all(), 'overdue_balance' => $balance]
        );

        if ($alerta->wasRecentlyCreated) {
            $managers = User::whereHas('roleScopes.role', fn ($q) => $q->whereIn('code', ['general_manager', 'branch_manager']))->get();
            $managers = $managers->filter(fn ($m) => $m->hasPermissionTo('delinquency.decide_global') || ($m->hasPermissionTo('delinquency.decide_branch') && $m->hasScopeForBranch($d->branch_id)));

            Notification::send($managers, new NotificacionEventoDominio([
                'title' => "Alerta de Morosidad: {$d->distributor_number}",
                'description' => 'La distribuidora ha sumado 3 cortes consecutivos sin pagar. Saldo vencido: $'.number_format((float) $balance, 2),
                'deep_link' => '/riesgo',
            ], true));
        }

        return $alerta;
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

    public function solicitarRetiro(Distribuidora $d, User $actor, string $reason): SolicitudRetiroMorosidad
    {
        $isGlobal = $actor->hasPermissionTo('delinquency_removal.decide_global');
        $isBranch = $actor->hasPermissionTo('delinquency_removal.decide_branch') && $actor->hasScopeForBranch($d->branch_id);
        $isCoord = $actor->hasPermissionTo('delinquency_removal.request_assigned') && $d->coordinadorVigente?->coordinator_id === $actor->id;
        $isOwn = $actor->distribuidora?->id === $d->id;

        abort_unless($isGlobal || $isBranch || $isCoord || $isOwn, 403);

        $overdue = RelacionDistribuidora::where('distributor_id', $d->id)
            ->where('payment_deadline_at', '<', now())
            ->where('balance', '>', 0)
            ->exists();

        if ($overdue) {
            throw new RuntimeException('DISTRIBUTOR_NOT_REGULARIZED');
        }

        $block = DB::table('distributor_operational_blocks')
            ->where('distributor_id', $d->id)
            ->where('type', 'DELINQUENCY')
            ->where('status', 'ACTIVE')
            ->first();

        if (! $block) {
            throw new RuntimeException('DELINQUENCY_BLOCK_NOT_FOUND');
        }

        return SolicitudRetiroMorosidad::create([
            'distributor_id' => $d->id,
            'block_id' => $block->id,
            'branch_id' => $d->branch_id,
            'reason' => $reason,
            'requested_by' => $actor->id,
        ]);
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
                DB::table('distributor_operational_blocks')->where('id', $r->block_id)->where('status', 'ACTIVE')->update(['status' => 'RELEASED', 'ends_at' => DB::raw('GREATEST(NOW(), DATE_ADD(starts_at, INTERVAL 1 SECOND))'), 'updated_at' => now()]);
            }
        });
    }
}
