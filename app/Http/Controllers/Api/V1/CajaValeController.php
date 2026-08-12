<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ExcepcionVale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Vale\AplicarModificacionValeRequest;
use App\Http\Requests\Api\V1\Vale\DecidirModificacionValeRequest;
use App\Http\Requests\Api\V1\Vale\FeriarValeRequest;
use App\Http\Requests\Api\V1\Vale\LiberarValeRequest;
use App\Http\Requests\Api\V1\Vale\SolicitarModificacionValeRequest;
use App\Http\Resources\Api\V1\Vale\ValeCajaResource;
use App\Models\SolicitudModificacionVale;
use App\Models\Vale;
use App\Services\Vale\ServicioCajaVale;
use App\Services\Vale\ServicioModificacionAutorizadaVale;
use Illuminate\Http\Request;

final class CajaValeController extends Controller
{
    public function search(Request $request)
    {
        $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $user = $request->user();
        if (! $user->hasPermissionTo('vouchers.cash_branch')) {
            throw new ExcepcionVale('VOUCHER_CASH_FORBIDDEN', 'No tienes permiso de caja.', 403);
        }
        $branches = $user->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->pluck('branch_id');
        $term = $request->string('search')->toString();
        $query = Vale::query()->with(['cliente.domicilioVigente', 'cliente.cuentaBancariaVigente', 'distribuidora.usuario', 'versionProducto'])->whereIn('branch_id', $branches)->where(function ($query) use ($term): void {
            $query->where('folio', 'ilike', '%'.$term.'%')->orWhereHas('cliente', fn ($client) => $client->whereRaw("concat_ws(' ', first_name, first_last_name, second_last_name) ilike ?", ['%'.$term.'%']));
        });

        return ValeCajaResource::collection($query->latest('generated_at')->limit(25)->get());
    }

    public function show(Vale $vale, Request $request): ValeCajaResource
    {
        if (! $request->user()->hasPermissionTo('vouchers.cash_branch') || ! $request->user()->hasScopeForBranch($vale->branch_id)) {
            throw new ExcepcionVale('VOUCHER_BRANCH_FORBIDDEN', 'Vale fuera de sucursal.', 404);
        }

        return new ValeCajaResource($vale->load(['cliente.domicilioVigente', 'cliente.cuentaBancariaVigente', 'distribuidora.usuario', 'versionProducto', 'parcialidades']));
    }

    public function release(Vale $vale, LiberarValeRequest $request, ServicioCajaVale $service): ValeCajaResource
    {
        return new ValeCajaResource($service->liberar($vale, $request->user(), $request->integer('lock_version'))->load(['cliente.domicilioVigente', 'cliente.cuentaBancariaVigente', 'distribuidora.usuario', 'versionProducto']));
    }

    public function cash(Vale $vale, FeriarValeRequest $request, ServicioCajaVale $service): ValeCajaResource
    {
        return new ValeCajaResource($service->feriar($vale, $request->user(), $request->string('bank_transaction_number')->toString(), $request->integer('lock_version'))->load(['cliente.domicilioVigente', 'cliente.cuentaBancariaVigente', 'distribuidora.usuario', 'versionProducto']));
    }

    public function requestModification(Vale $vale, SolicitarModificacionValeRequest $request, ServicioModificacionAutorizadaVale $service)
    {
        return response()->json(['data' => $service->solicitar($vale, $request->user(), $request->validated('fields'), $request->validated('reason'))], 201);
    }

    public function listModifications(Request $request)
    {
        $user = $request->user();
        if (! $user->hasPermissionTo('voucher_modifications.authorize_global') && ! $user->hasPermissionTo('voucher_modifications.authorize_branch')) {
            throw new ExcepcionVale('MODIFICATION_AUTHORIZE_FORBIDDEN', 'Sin permiso.', 403);
        }
        $query = SolicitudModificacionVale::query()->with('vale.cliente')->where('status', 'REQUESTED');
        if (! $user->hasPermissionTo('voucher_modifications.authorize_global')) {
            $query->whereIn('branch_id', $user->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->where('scope_type', 'BRANCH')->select('branch_id'));
        }

        return response()->json(['data' => $query->latest()->get()]);
    }

    public function decideModification(SolicitudModificacionVale $solicitud, DecidirModificacionValeRequest $request, ServicioModificacionAutorizadaVale $service)
    {
        return response()->json(['data' => $service->decidir($solicitud, $request->user(), $request->validated('decision') === 'AUTHORIZE', $request->validated('reason'), $request->integer('lock_version'))]);
    }

    public function applyModification(SolicitudModificacionVale $solicitud, AplicarModificacionValeRequest $request, ServicioModificacionAutorizadaVale $service)
    {
        $validated = $request->validated();

        return response()->json(['data' => $service->aplicar($solicitud, $request->user(), $validated['token'], $validated['changes'], $request->integer('lock_version'))]);
    }
}
