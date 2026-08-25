<?php

namespace App\Services\Riesgo;

use App\Models\AlertaRiesgoDistribuidora;
use App\Models\AuditLog;
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
    public function evaluarCorteConciliado(string $processRunId, string $branchId): void
    {
        $relations = RelacionDistribuidora::query()
            ->where('process_run_id', $processRunId)
            ->where('branch_id', $branchId)
            ->get(['distributor_id', 'payment_deadline_at']);

        if ($relations->isEmpty()) {
            return;
        }

        $evaluationTime = $relations
            ->max(fn (RelacionDistribuidora $relation) => $relation->payment_deadline_at)
            ->addDay();

        Distribuidora::query()
            ->with('usuario')
            ->whereIn('id', $relations->pluck('distributor_id')->unique())
            ->each(fn (Distribuidora $distributor) => $this->evaluar($distributor, $evaluationTime));
    }

    public function evaluar(Distribuidora $d, ?CarbonImmutable $asOf = null): ?AlertaRiesgoDistribuidora
    {
        $evaluationTime = ($asOf ?? CarbonImmutable::now('UTC'))->setTimezone(config('relations.timezone'));
        $lastRegularizedCutoff = SolicitudRetiroMorosidad::query()
            ->where('distributor_id', $d->id)
            ->where('status', 'AUTHORIZED')
            ->whereNotNull('regularized_relation_id')
            ->with('relacionRegularizada:id,cutoff_at')
            ->latest('decided_at')
            ->first()?->relacionRegularizada?->cutoff_at;
        $overdue = RelacionDistribuidora::where('distributor_id', $d->id)
            ->where('payment_deadline_at', '<=', $evaluationTime)
            ->when($lastRegularizedCutoff, fn ($query, $cutoff) => $query->where('cutoff_at', '>', $cutoff))
            ->latest('cutoff_at')
            ->get();
        $consecutive = $overdue->takeUntil(fn (RelacionDistribuidora $relation): bool => ! $this->esIncumplida($relation));

        $consecutiveCount = $consecutive->count();

        $isMorosa = DB::table('distributor_operational_blocks')
            ->where('distributor_id', $d->id)
            ->where('type', 'DELINQUENCY')
            ->where('status', 'ACTIVE')
            ->exists();

        if ($consecutiveCount >= 1 && ! $isMorosa) {
            $balance = $this->saldoPendienteActual($d);
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

        $balance = $this->saldoPendienteActual($d);

        $alerta = AlertaRiesgoDistribuidora::firstOrCreate(
            ['distributor_id' => $d->id, 'type' => 'THREE_CONSECUTIVE_DEFAULTS', 'status' => 'OPEN'],
            ['branch_id' => $d->branch_id, 'consecutive_defaults' => 3, 'relation_ids' => $consecutive->pluck('id')->all(), 'overdue_balance' => $balance]
        );
        $alerta->forceFill([
            'branch_id' => $d->branch_id,
            'consecutive_defaults' => $consecutiveCount,
            'relation_ids' => $consecutive->pluck('id')->all(),
            'overdue_balance' => $balance,
        ])->save();

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

    private function esIncumplida(RelacionDistribuidora $relation): bool
    {
        return bccomp((string) $relation->balance, '0', 4) > 0
            || bccomp((string) $relation->rolled_forward_amount, '0', 4) > 0;
    }

    private function saldoPendienteActual(Distribuidora $distributor): string
    {
        return (string) RelacionDistribuidora::query()
            ->where('distributor_id', $distributor->id)
            ->where('balance', '>', 0)
            ->sum('balance');
    }

    private function regularizacionParaRetiro(Distribuidora $distributor): array
    {
        $block = DB::table('distributor_operational_blocks')
            ->where('distributor_id', $distributor->id)
            ->where('type', 'DELINQUENCY')
            ->where('status', 'ACTIVE')
            ->first();
        if (! $block) {
            return [null, null];
        }

        $alert = AlertaRiesgoDistribuidora::query()->find($block->source_id);
        $cycleCutoff = $alert === null || empty($alert->relation_ids)
            ? null
            : RelacionDistribuidora::query()->whereIn('id', $alert->relation_ids)->max('cutoff_at');
        $regularizedRelation = RelacionDistribuidora::query()
            ->where('distributor_id', $distributor->id)
            ->where('financial_status', 'SETTLED')
            ->when($cycleCutoff, fn ($query, $cutoff) => $query->where('cutoff_at', '>=', $cutoff))
            ->latest('cutoff_at')
            ->first();

        return [$block, $regularizedRelation];
    }

    public function notificarDeudaRegularizada(RelacionDistribuidora $relation): void
    {
        [$block, $regularizedRelation] = $this->regularizacionParaRetiro($relation->distribuidora);
        if (! $block || $regularizedRelation?->id !== $relation->id) {
            return;
        }
        $audit = AuditLog::firstOrCreate(
            ['entity_type' => 'distributor_relation', 'entity_id' => $relation->id, 'event_name' => 'DELINQUENCY_DEBT_SETTLED'],
            ['branch_id' => $relation->branch_id, 'new_value' => ['distributor_id' => $relation->distributor_id, 'payment_reference' => $relation->payment_reference], 'result' => 'SUCCESS'],
        );
        if (! $audit->wasRecentlyCreated) {
            return;
        }

        $managers = User::whereHas('roleScopes.role', fn ($query) => $query->whereIn('code', ['general_manager', 'branch_manager']))->get()
            ->filter(fn (User $manager) => $manager->hasPermissionTo('delinquency_removal.decide_global') || ($manager->hasPermissionTo('delinquency_removal.decide_branch') && $manager->hasScopeForBranch($relation->branch_id)));
        Notification::send($managers, new NotificacionEventoDominio([
            'title' => "Deuda de morosidad liquidada: {$relation->distribuidora->distributor_number}",
            'description' => 'La distribuidora liquidó la relación que acumulaba su deuda morosa. El retiro del bloqueo ya puede resolverse.',
            'deep_link' => '/riesgo',
        ], true));
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
        $isOwn = $actor->distribuidora?->id === $d->id;
        abort_unless($isOwn, 403);

        [$block, $regularizedRelation] = $this->regularizacionParaRetiro($d);
        if ($regularizedRelation === null) {
            throw new RuntimeException('DISTRIBUTOR_NOT_REGULARIZED');
        }
        if (! $block) {
            throw new RuntimeException('DELINQUENCY_BLOCK_NOT_FOUND');
        }

        if (SolicitudRetiroMorosidad::query()->where('block_id', $block->id)->where('status', 'REQUESTED')->exists()) {
            throw new RuntimeException('REMOVAL_ALREADY_REQUESTED');
        }

        return SolicitudRetiroMorosidad::create([
            'distributor_id' => $d->id,
            'block_id' => $block->id,
            'regularized_relation_id' => $regularizedRelation->id,
            'branch_id' => $d->branch_id,
            'reason' => $reason,
            'requested_by' => $actor->id,
        ]);
    }

    public function retirarDirectamente(Distribuidora $distributor, User $actor, string $reason): SolicitudRetiroMorosidad
    {
        abort_unless($actor->hasPermissionTo('delinquency_removal.decide_global') || ($actor->hasPermissionTo('delinquency_removal.decide_branch') && $actor->hasScopeForBranch($distributor->branch_id)), 403);
        [$block, $regularizedRelation] = $this->regularizacionParaRetiro($distributor);
        if (! $block) {
            throw new RuntimeException('DELINQUENCY_BLOCK_NOT_FOUND');
        }
        if ($regularizedRelation === null) {
            throw new RuntimeException('DISTRIBUTOR_NOT_REGULARIZED');
        }

        $request = SolicitudRetiroMorosidad::query()
            ->where('block_id', $block->id)
            ->where('status', 'REQUESTED')
            ->first() ?? SolicitudRetiroMorosidad::create([
                'distributor_id' => $distributor->id,
                'block_id' => $block->id,
                'regularized_relation_id' => $regularizedRelation->id,
                'branch_id' => $distributor->branch_id,
                'reason' => $reason,
                'requested_by' => $actor->id,
            ]);
        $this->decidirRetiro($request, $actor, true, $reason);

        return $request->fresh();
    }

    public function estadoRetiro(Distribuidora $distributor): array
    {
        [$block, $regularizedRelation] = $this->regularizacionParaRetiro($distributor);
        $pending = $block ? SolicitudRetiroMorosidad::query()->where('block_id', $block->id)->where('status', 'REQUESTED')->first() : null;

        return ['block' => $block, 'regularized_relation' => $regularizedRelation, 'pending_request' => $pending];
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
