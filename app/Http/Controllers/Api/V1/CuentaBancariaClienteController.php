<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ExcepcionCliente;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cliente\CrearCuentaBancariaClienteRequest;
use App\Http\Resources\Api\V1\Cliente\CuentaBancariaClienteResource;
use App\Models\Cliente;
use App\Services\Cliente\AuditorCliente;
use App\Services\Cliente\ServicioCuentaBancariaCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CuentaBancariaClienteController extends Controller
{
    public function index(Cliente $client, AuditorCliente $auditor): AnonymousResourceCollection
    {
        if (Gate::denies('viewBankAccounts', $client)) {
            $this->auditarRechazo($client, $auditor);
            throw new ExcepcionCliente('CLIENT_SCOPE_DENIED', 'El cliente no está dentro del alcance autorizado.', 403);
        }

        return CuentaBancariaClienteResource::collection($client->cuentasBancarias()->latest('starts_at')->paginate(20));
    }

    public function store(CrearCuentaBancariaClienteRequest $request, Cliente $client, ServicioCuentaBancariaCliente $servicio, AuditorCliente $auditor): JsonResponse
    {
        if (Gate::denies('manageBankAccounts', $client)) {
            $this->auditarRechazo($client, $auditor);
            throw new ExcepcionCliente('CLIENT_SCOPE_DENIED', 'El cliente no está dentro del alcance autorizado.', 403);
        }

        $cuenta = $servicio->registrar($client, $request->validated(), $request->user());

        return response()->json(['data' => (new CuentaBancariaClienteResource($cuenta))->resolve($request)], 201);
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
