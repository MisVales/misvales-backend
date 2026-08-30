<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ExcepcionCliente;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cliente\CompletarBorradorClienteRequest;
use App\Http\Requests\Api\V1\Cliente\CrearBorradorClienteRequest;
use App\Http\Requests\Api\V1\Cliente\CrearClienteParaValeRequest;
use App\Http\Requests\Api\V1\Cliente\CrearClienteRequest;
use App\Http\Requests\Api\V1\Cliente\EnlistarClientesRequest;
use App\Http\Resources\Api\V1\Cliente\ClienteDetalleResource;
use App\Http\Resources\Api\V1\Cliente\ClienteResource;
use App\Models\Cliente;
use App\Models\ClientRegistrationDraft;
use App\Services\Cliente\AuditorCliente;
use App\Services\Cliente\ServicioConsultaCliente;
use App\Services\Cliente\ServicioRegistroCliente;
use App\Services\Cliente\ServicioRegistroClienteBorrador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ClienteController extends Controller
{
    public function index(EnlistarClientesRequest $request, ServicioConsultaCliente $servicio): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Cliente::class);

        return ClienteResource::collection($servicio->listar($request->validated(), $request->user()));
    }

    public function store(CrearClienteRequest $request, ServicioRegistroCliente $servicio): JsonResponse
    {
        if (Gate::denies('create', Cliente::class)) {
            throw new ExcepcionCliente('AUTH_SCOPE_DENIED', 'No tiene alcance para registrar clientes.', 403);
        }

        $cliente = $servicio->registrar($request->validated(), $request->user());

        return response()->json([
            'data' => (new ClienteResource($cliente))->resolve($request),
        ], 201);
    }

    public function storeForVoucher(CrearClienteParaValeRequest $request): JsonResponse
    {
        $cliente = app(ServicioRegistroClienteBorrador::class)->completar(
            ClientRegistrationDraft::query()->findOrFail($request->validated('registration_draft_id')),
            $request->user(),
        );

        return response()->json([
            'data' => (new ClienteResource($cliente))->resolve($request),
        ], 201);
    }

    public function createRegistrationDraft(CrearBorradorClienteRequest $request, ServicioRegistroClienteBorrador $servicio): JsonResponse
    {
        $draft = $servicio->crear($request->validated(), $request->user());

        return response()->json(['data' => [
            'id' => $draft->id,
            'status' => $draft->status,
            'payload' => $draft->payload,
        ]], 201);
    }

    public function completeRegistrationDraft(ClientRegistrationDraft $draft, CompletarBorradorClienteRequest $request, ServicioRegistroClienteBorrador $servicio): JsonResponse
    {
        $cliente = $servicio->completar($draft, $request->user());

        return response()->json(['data' => (new ClienteResource($cliente))->resolve($request)], 201);
    }

    public function show(
        Cliente $client,
        ServicioConsultaCliente $servicio,
        AuditorCliente $auditor,
    ): ClienteDetalleResource {
        $asignacion = $client->asignacionVigente()->first();
        if (Gate::denies('view', $client)) {
            $auditor->registrar(
                'CLIENT_SCOPE_ACCESS_REJECTED', $client->id, request()->user(),
                $asignacion?->branch_id, $asignacion?->distributor_id, 'REJECTED', 'CLIENT_SCOPE_DENIED',
            );
            throw new ExcepcionCliente('CLIENT_SCOPE_DENIED', 'El cliente no existe o no está dentro del alcance autorizado.', 404);
        }

        if (Gate::allows('viewSensitive', $client)) {
            $auditor->registrar(
                'CLIENT_SENSITIVE_DATA_VIEWED', $client->id, request()->user(),
                $asignacion?->branch_id, $asignacion?->distributor_id,
            );
        }

        return new ClienteDetalleResource($servicio->cargarDetalle($client, request()->user()));
    }
}
