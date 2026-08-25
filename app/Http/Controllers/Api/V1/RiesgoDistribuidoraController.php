<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlertaRiesgoDistribuidora;
use App\Models\BloqueoOperativoDistribuidora;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\RelacionDistribuidora;
use App\Models\SolicitudRetiroMorosidad;
use App\Services\Riesgo\ServicioMorosidadDistribuidora;
use Illuminate\Http\Request;

final class RiesgoDistribuidoraController extends Controller
{
    public function me(Request $request, ServicioMorosidadDistribuidora $service)
    {
        abort_unless($request->user()->hasPermissionTo('risk.view_own') && $request->user()->distribuidora, 403);
        $block = BloqueoOperativoDistribuidora::query()->where('distributor_id', $request->user()->distribuidora->id)->where('type', 'DELINQUENCY')->where('status', 'ACTIVE')->first();

        $removal = $service->estadoRetiro($request->user()->distribuidora);

        return response()->json(['data' => [
            'blocked' => (bool) $block,
            'reason' => $block?->reason,
            'can_pay' => true,
            'can_clarify' => true,
            'can_request_removal' => $block !== null && $removal['regularized_relation'] !== null && $removal['pending_request'] === null,
            'has_pending_removal_request' => $removal['pending_request'] !== null,
            'regularized_relation_id' => $removal['regularized_relation']?->id,
        ]]);
    }

    public function alerts(Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('risk.view_global') || $u->hasPermissionTo('risk.view_branch') || $u->hasPermissionTo('risk.view_assigned'), 403);
        $q = AlertaRiesgoDistribuidora::query()
            ->with(['distribuidora.usuario', 'distribuidora.sucursal'])
            ->latest();
        if (! $u->hasPermissionTo('risk.view_global')) {
            $branches = $u->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $q->whereIn('branch_id', $branches);
            if ($u->hasPermissionTo('risk.view_assigned')) {
                $q->whereIn('distributor_id', CoordinatorDistributorAssignment::where('coordinator_id', $u->id)->where('status', 'ACTIVE')->pluck('distributor_id'));
            }
        }

        $alerts = $q->get();
        $pendingRequests = SolicitudRetiroMorosidad::query()
            ->whereIn('distributor_id', $alerts->pluck('distributor_id'))
            ->where('status', 'REQUESTED')
            ->with(['solicitante', 'distribuidora.usuario', 'distribuidora.sucursal'])
            ->latest()
            ->get()
            ->keyBy('distributor_id');
        $alerts->each(fn (AlertaRiesgoDistribuidora $alert) => $alert->setAttribute('pending_removal_request', $pendingRequests->get($alert->distributor_id)));

        return response()->json(['data' => $alerts]);
    }

    public function decide(AlertaRiesgoDistribuidora $alerta, Request $r, ServicioMorosidadDistribuidora $s)
    {
        $d = $r->validate(['decision' => ['required', 'in:APPLY,DO_NOT_APPLY'], 'reason' => ['required', 'string', 'max:1000']]);
        $s->decidir($alerta, $r->user(), $d['decision'] === 'APPLY', $d['reason']);

        return response()->json(['data' => $alerta->fresh(['distribuidora.usuario', 'distribuidora.sucursal'])]);
    }

    public function requestRemoval(Distribuidora $distribuidora, Request $r, ServicioMorosidadDistribuidora $s)
    {
        $d = $r->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $s->solicitarRetiro($distribuidora, $r->user(), $d['reason'])], 201);
    }

    public function removeDirectly(Distribuidora $distribuidora, Request $request, ServicioMorosidadDistribuidora $service)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return response()->json(['data' => $service->retirarDirectamente($distribuidora, $request->user(), $validated['reason'])]);
    }

    public function removals(Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('delinquency_removal.decide_global') || $u->hasPermissionTo('delinquency_removal.decide_branch'), 403);
        $q = SolicitudRetiroMorosidad::query()
            ->with(['distribuidora.usuario', 'distribuidora.sucursal', 'solicitante', 'decididoPor'])
            ->latest();
        if (! $u->hasPermissionTo('delinquency_removal.decide_global')) {
            $branches = $u->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $q->whereIn('branch_id', $branches);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function delinquencyBlocks(Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('risk.view_global') || $u->hasPermissionTo('risk.view_branch') || $u->hasPermissionTo('risk.view_assigned'), 403);

        $q = BloqueoOperativoDistribuidora::query()
            ->where('type', 'DELINQUENCY')
            ->where('status', 'ACTIVE')
            ->with(['distribuidora.usuario', 'distribuidora.sucursal', 'creadoPor'])
            ->latest('starts_at');

        if (! $u->hasPermissionTo('risk.view_global')) {
            $branches = $u->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $q->whereHas('distribuidora', fn ($d) => $d->whereIn('branch_id', $branches));
            if ($u->hasPermissionTo('risk.view_assigned')) {
                $assignedDistributors = CoordinatorDistributorAssignment::where('coordinator_id', $u->id)->where('status', 'ACTIVE')->pluck('distributor_id');
                $q->whereIn('distributor_id', $assignedDistributors);
            }
        }

        $blocks = $q->get()->map(function (BloqueoOperativoDistribuidora $block) {
            $overdueRelations = RelacionDistribuidora::where('distributor_id', $block->distributor_id)
                ->where('payment_deadline_at', '<', now())
                ->where('balance', '>', 0)
                ->get();

            $overdueBalance = $overdueRelations->reduce(fn ($sum, $rel) => bcadd($sum, (string) $rel->balance, 4), '0.0000');
            $pendingRequest = SolicitudRetiroMorosidad::where('distributor_id', $block->distributor_id)
                ->where('status', 'REQUESTED')
                ->first();

            return [
                'id' => $block->id,
                'distributor_id' => $block->distributor_id,
                'type' => $block->type,
                'status' => $block->status,
                'reason' => $block->reason,
                'starts_at' => $block->starts_at?->toIso8601String(),
                'distribuidora' => $block->distribuidora,
                'creado_por' => $block->creadoPor,
                'overdue_balance' => $overdueBalance,
                'overdue_count' => $overdueRelations->count(),
                'is_regularized' => bccomp($overdueBalance, '0', 4) === 0,
                'has_pending_request' => (bool) $pendingRequest,
                'pending_request_id' => $pendingRequest?->id,
            ];
        });

        return response()->json(['data' => $blocks]);
    }

    public function decideRemoval(SolicitudRetiroMorosidad $solicitud, Request $r, ServicioMorosidadDistribuidora $s)
    {
        $d = $r->validate(['decision' => ['required', 'in:AUTHORIZE,REJECT'], 'reason' => ['required', 'string', 'max:1000']]);
        $s->decidirRetiro($solicitud, $r->user(), $d['decision'] === 'AUTHORIZE', $d['reason']);

        return response()->json(['data' => $solicitud->fresh()]);
    }
}
