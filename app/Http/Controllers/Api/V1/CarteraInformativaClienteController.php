<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ExcepcionCliente;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cliente\ActualizarMovimientoCarteraRequest;
use App\Http\Requests\Api\V1\Cliente\RegistrarMovimientoCarteraRequest;
use App\Http\Resources\Api\V1\Cliente\MovimientoCarteraResource;
use App\Models\Cliente;
use App\Models\MovimientoCarteraCliente;
use App\Services\Cliente\AuditorCliente;
use App\Services\Cliente\ServicioCarteraInformativa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CarteraInformativaClienteController extends Controller
{
    public function index(Cliente $client, ServicioCarteraInformativa $servicio, AuditorCliente $auditor): AnonymousResourceCollection
    {
        if (Gate::denies('viewPortfolio', $client)) {
            $this->auditarRechazo($client, $auditor);
            throw new ExcepcionCliente('CLIENT_SCOPE_DENIED', 'El cliente no está dentro del alcance autorizado.', 403);
        }

        return MovimientoCarteraResource::collection(
            $client->movimientosCartera()->latest('occurred_at')->paginate(20),
        )->additional(['summary' => $servicio->resumen($client->id)]);
    }

    public function store(RegistrarMovimientoCarteraRequest $request, Cliente $client, ServicioCarteraInformativa $servicio, AuditorCliente $auditor): JsonResponse
    {
        if (Gate::denies('managePortfolio', $client)) {
            $this->auditarRechazo($client, $auditor);
            throw new ExcepcionCliente('CLIENT_SCOPE_DENIED', 'El cliente no está dentro del alcance autorizado.', 403);
        }

        $movimiento = $servicio->registrar($client, $request->validated(), $request->user());

        return response()->json([
            'data' => (new MovimientoCarteraResource($movimiento))->resolve($request),
            'summary' => $servicio->resumen($client->id),
        ], 201);
    }

    public function update(
        ActualizarMovimientoCarteraRequest $request,
        Cliente $client,
        MovimientoCarteraCliente $entry,
        ServicioCarteraInformativa $servicio,
        AuditorCliente $auditor,
    ): JsonResponse {
        if (Gate::denies('managePortfolio', $client)) {
            $this->auditarRechazo($client, $auditor);
            throw new ExcepcionCliente('CLIENT_SCOPE_DENIED', 'El cliente no está dentro del alcance autorizado.', 403);
        }

        $movimiento = $servicio->actualizar($client, $entry, $request->validated(), $request->user());

        return response()->json([
            'data' => (new MovimientoCarteraResource($movimiento))->resolve($request),
            'summary' => $servicio->resumen($client->id),
        ]);
    }

    private function auditarRechazo(Cliente $cliente, AuditorCliente $auditor): void
    {
        $asignacion = $cliente->asignacionVigente()->first();
        $auditor->registrar(
            'CLIENT_SCOPE_ACCESS_REJECTED', $cliente->id, request()->user(),
            $asignacion?->branch_id, $asignacion?->distributor_id, 'REJECTED', 'CLIENT_SCOPE_DENIED',
        );
    }
}
