<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AlertaRiesgoDistribuidora;
use App\Models\BloqueoOperativoDistribuidora;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\SolicitudRetiroMorosidad;
use App\Services\Riesgo\ServicioMorosidadDistribuidora;
use Illuminate\Http\Request;

final class RiesgoDistribuidoraController extends Controller
{
    public function me(Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('risk.view_own') && $request->user()->distribuidora, 403);
        $block = BloqueoOperativoDistribuidora::query()->where('distributor_id', $request->user()->distribuidora->id)->where('type', 'DELINQUENCY')->where('status', 'ACTIVE')->first();

        return response()->json(['data' => ['blocked' => (bool) $block, 'reason' => $block?->reason, 'can_pay' => true, 'can_clarify' => true]]);
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

        return response()->json(['data' => $q->get()]);
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

    public function decideRemoval(SolicitudRetiroMorosidad $solicitud, Request $r, ServicioMorosidadDistribuidora $s)
    {
        $d = $r->validate(['decision' => ['required', 'in:AUTHORIZE,REJECT'], 'reason' => ['required', 'string', 'max:1000']]);
        $s->decidirRetiro($solicitud, $r->user(), $d['decision'] === 'AUTHORIZE', $d['reason']);

        return response()->json(['data' => $solicitud->fresh()]);
    }
}
