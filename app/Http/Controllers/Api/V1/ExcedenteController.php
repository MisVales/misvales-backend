<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExcedenteDistribuidora;
use App\Models\SolicitudDevolucionExcedente;
use App\Services\Excedente\ServicioExcedente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ExcedenteController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $q = ExcedenteDistribuidora::query()->latest();
        if ($u->hasPermissionTo('surpluses.view_global')) {
        } elseif ($u->hasPermissionTo('surpluses.view_branch')) {
            $branches = $u->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $q->whereHas('distributor', fn ($x) => $x->whereIn('branch_id', $branches));
        } elseif ($u->hasPermissionTo('surpluses.view_own') && $u->distribuidora) {
            $q->where('distributor_id', $u->distribuidora->id);
        } else {
            $q->whereRaw('1=0');
        }

        return response()->json(['data' => $q->get()]);
    }

    public function credit(ExcedenteDistribuidora $excedente, Request $r, ServicioExcedente $s)
    {
        return response()->json(['data' => $s->elegirCredito($excedente, $r->user())]);
    }

    public function refund(ExcedenteDistribuidora $excedente, Request $r, ServicioExcedente $s)
    {
        return response()->json(['data' => $s->solicitarDevolucion($excedente, $r->user())], 201);
    }

    public function refunds(Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('refunds.authorize_global') || $u->hasPermissionTo('refunds.authorize_branch') || $u->hasPermissionTo('refunds.execute_branch'), 403);
        $q = SolicitudDevolucionExcedente::query()->latest();
        if (! $u->hasPermissionTo('refunds.authorize_global')) {
            $branches = $u->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $q->whereIn('branch_id', $branches);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function decide(SolicitudDevolucionExcedente $solicitud, Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('refunds.authorize_global') || ($u->hasPermissionTo('refunds.authorize_branch') && $u->hasScopeForBranch($solicitud->branch_id)), 403);
        abort_unless($solicitud->status === 'REQUESTED', 409);
        $d = $r->validate(['decision' => ['required', 'in:AUTHORIZE,REJECT'], 'reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($solicitud, $u, $d) {
            $s = SolicitudDevolucionExcedente::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();
            $surplus = ExcedenteDistribuidora::whereKey($s->surplus_id)->lockForUpdate()->firstOrFail();
            $s->update(['status' => $d['decision'] === 'AUTHORIZE' ? 'AUTHORIZED' : 'REJECTED', 'decided_by' => $u->id, 'decision_reason' => $d['reason']]);
            if ($d['decision'] === 'REJECT') {
                $surplus->update(['available_amount' => $surplus->reserved_amount, 'reserved_amount' => '0.0000', 'status' => 'PENDING_DECISION']);
            }
        });

        return response()->json(['data' => $solicitud->fresh()]);
    }

    public function execute(SolicitudDevolucionExcedente $solicitud, Request $r)
    {
        $u = $r->user();
        abort_unless($u->hasPermissionTo('refunds.execute_branch') && $u->hasScopeForBranch($solicitud->branch_id), 403);
        abort_unless($solicitud->status === 'AUTHORIZED', 409);
        $d = $r->validate(['method' => ['required', 'string', 'max:50'], 'reference' => ['required', 'string', 'max:100'], 'evidence_media_id' => ['required', 'uuid', 'exists:media_files,id']]);
        DB::transaction(function () use ($solicitud, $u, $d) {
            $s = SolicitudDevolucionExcedente::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();
            $surplus = ExcedenteDistribuidora::whereKey($s->surplus_id)->lockForUpdate()->firstOrFail();
            $s->update(['status' => 'EXECUTED', 'executed_by' => $u->id, 'execution_method' => $d['method'], 'execution_reference' => $d['reference'], 'evidence_media_id' => $d['evidence_media_id'], 'executed_at' => now()]);
            $surplus->update(['reserved_amount' => '0.0000', 'status' => 'REFUNDED']);
        });

        return response()->json(['data' => $solicitud->fresh()]);
    }
}
