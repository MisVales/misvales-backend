<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Conciliacion\ImportarArchivoBancarioRequest;
use App\Models\AclaracionPago;
use App\Models\ImportacionArchivoBancario;
use App\Models\MovimientoBancario;
use App\Models\RelacionDistribuidora;
use App\Models\SolicitudConciliacionManual;
use App\Services\Conciliacion\ServicioImportacionBancaria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConciliacionBancariaController extends Controller
{
    public function import(ImportarArchivoBancarioRequest $request, ServicioImportacionBancaria $service)
    {
        $branch = $request->user()->branch_id;
        abort_unless($branch, 403);

        return response()->json(['data' => $service->importar($request->file('file'), $request->user(), $branch)], 201);
    }

    public function imports(Request $request)
    {
        $global = $request->user()->hasPermissionTo('bank_imports.view_global');
        abort_unless($global || $request->user()->hasPermissionTo('bank_imports.view_branch'), 403);
        $query = ImportacionArchivoBancario::query();
        if (! $global) {
            $branches = $request->user()->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $query->whereIn('branch_id', $branches);
        }

        return response()->json(['data' => $query->latest()->paginate(25)]);
    }

    public function movements(Request $request)
    {
        $global = $request->user()->hasPermissionTo('bank_movements.view_global');
        abort_unless($global || $request->user()->hasPermissionTo('bank_movements.view_branch'), 403);
        $query = MovimientoBancario::query();
        if (! $global) {
            $branches = $request->user()->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
            $query->whereHas('import', fn ($q) => $q->whereIn('branch_id', $branches));
        }

        return response()->json(['data' => $query->latest()->paginate(50)]);
    }

    public function clarify(RelacionDistribuidora $relacion, Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('payment_clarifications.create_own') && $request->user()->distribuidora?->id === $relacion->distributor_id, 403);
        $data = $request->validate(['evidence_media_id' => ['required', 'uuid', 'exists:media_files,id'], 'reason' => ['required', 'string', 'max:1000']]);
        $item = AclaracionPago::create($data + ['folio' => 'ACL-'.strtoupper(Str::random(12)), 'distributor_id' => $relacion->distributor_id, 'relation_id' => $relacion->id]);
        $relacion->update(['review_status' => 'CLARIFICATION_OPEN']);

        return response()->json(['data' => $item], 201);
    }

    public function requestManual(MovimientoBancario $movimiento, Request $request)
    {
        abort_unless($request->user()->hasPermissionTo('manual_reconciliation.request_branch') && $request->user()->hasScopeForBranch($movimiento->import->branch_id), 403);
        abort_unless($movimiento->classification === 'UNRECONCILED', 409);
        $data = $request->validate(['relation_id' => ['required', 'uuid', 'exists:distributor_relations,id'], 'clarification_id' => ['required', 'uuid', 'exists:payment_clarifications,id'], 'reason' => ['required', 'string', 'max:1000']]);
        $relation = RelacionDistribuidora::findOrFail($data['relation_id']);
        $clar = AclaracionPago::findOrFail($data['clarification_id']);
        abort_unless($relation->branch_id === $movimiento->import->branch_id && $clar->relation_id === $relation->id, 422);

        return response()->json(['data' => SolicitudConciliacionManual::create($data + ['bank_movement_id' => $movimiento->id, 'branch_id' => $relation->branch_id, 'requested_by' => $request->user()->id])], 201);
    }

    public function decideManual(SolicitudConciliacionManual $solicitud, Request $request)
    {
        $global = $request->user()->hasPermissionTo('manual_reconciliation.authorize_global');
        abort_unless($global || ($request->user()->hasPermissionTo('manual_reconciliation.authorize_branch') && $request->user()->hasScopeForBranch($solicitud->branch_id)), 403);
        abort_if($solicitud->requested_by === $request->user()->id, 403);
        abort_unless($solicitud->status === 'REQUESTED', 409);
        $data = $request->validate(['decision' => ['required', 'in:AUTHORIZE,REJECT']]);
        $solicitud->update(['status' => $data['decision'] === 'AUTHORIZE' ? 'AUTHORIZED' : 'REJECTED', 'authorized_by' => $request->user()->id, 'authorized_at' => now()]);

        return response()->json(['data' => $solicitud->fresh()]);
    }

    public function executeManual(SolicitudConciliacionManual $solicitud, Request $request, \App\Services\Pago\ServicioAplicacionPago $servicioPago)
    {
        abort_unless($request->user()->hasPermissionTo('manual_reconciliation.execute_branch') && $request->user()->hasScopeForBranch($solicitud->branch_id), 403);
        abort_unless($solicitud->status === 'AUTHORIZED', 409);
        DB::transaction(function () use ($solicitud, $request, $servicioPago) {
            $relation = RelacionDistribuidora::whereKey($solicitud->relation_id)->lockForUpdate()->firstOrFail();
            $movement = MovimientoBancario::whereKey($solicitud->bank_movement_id)->lockForUpdate()->firstOrFail();
            $before = ['balance' => $relation->balance, 'reconciled_total' => $relation->reconciled_total];
            
            $servicioPago->aplicar($movement, $relation);
            $relation->refresh();

            $solicitud->update([
                'status' => 'EXECUTED',
                'executed_by' => $request->user()->id,
                'executed_at' => now(),
                'before_snapshot' => $before,
                'after_snapshot' => ['balance' => $relation->balance, 'reconciled_total' => $relation->reconciled_total],
            ]);
        });

        return response()->json(['data' => $solicitud->fresh()]);
    }
}
