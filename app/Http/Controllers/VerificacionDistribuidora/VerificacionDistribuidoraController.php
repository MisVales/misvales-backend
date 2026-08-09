<?php

namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerificacionDistribuidora\ActualizarVisitaRequest;
use App\Http\Requests\VerificacionDistribuidora\AsignarVerificadorRequest;
use App\Http\Requests\VerificacionDistribuidora\FinalizarVisitaRequest;
use App\Http\Requests\VerificacionDistribuidora\IniciarVisitaRequest;
use App\Http\Resources\VerificacionDistribuidora\DistributorApplicationResource;
use App\Http\Resources\VerificacionDistribuidora\VerificationVisitResource;
use App\Services\VerificacionDistribuidora\ServicioConsultaExpedientes;
use App\Services\VerificacionDistribuidora\ServicioRevisionCoordinador;
use App\Services\VerificacionDistribuidora\ServicioVerificacionDistribuidora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificacionDistribuidoraController extends Controller
{
    public function __construct(
        private readonly ServicioVerificacionDistribuidora $verificacion,
        private readonly ServicioRevisionCoordinador $revision,
        private readonly ServicioConsultaExpedientes $consulta,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'estado' => 'nullable|string|max:50',
            'buscar' => 'nullable|string|max:120',
            'por_pagina' => 'nullable|integer|min:1|max:100',
        ]);

        return DistributorApplicationResource::collection(
            $this->consulta->listar((string) $request->user()->id, $filters),
        );
    }

    public function show(Request $request, string $application): DistributorApplicationResource
    {
        return new DistributorApplicationResource(
            $this->consulta->consultar($application, (string) $request->user()->id),
        );
    }

    public function verificadoresDisponibles(Request $request, string $application): JsonResponse
    {
        $applicationRecord = $this->consulta->consultar($application, (string) $request->user()->id);
        $users = $this->consulta->verificadoresDisponibles($application, (string) $request->user()->id);

        return response()->json(['data' => $users->map(fn ($user) => [
            'id' => $user->id,
            'nombre_completo' => $user->name,
            'sucursal_id' => $applicationRecord->branch_id,
            'estado' => $user->state,
        ])]);
    }

    public function asignarVerificador(
        AsignarVerificadorRequest $request,
        string $application,
    ): DistributorApplicationResource {
        $data = $request->validated();
        $this->revision->asignarVerificador(
            $application,
            (string) $request->user()->id,
            $data['verifier_id'],
            (int) $data['lock_version'],
        );

        return new DistributorApplicationResource(
            $this->consulta->consultar($application, (string) $request->user()->id),
        );
    }

    public function consultarAsignadas(Request $request)
    {
        return VerificationVisitResource::collection(
            $this->verificacion->consultarAsignadas((string) $request->user()->id),
        );
    }

    public function consultarVisita(Request $request, string $visit): VerificationVisitResource
    {
        return new VerificationVisitResource(
            $this->verificacion->consultarVisita($visit, (string) $request->user()->id),
        );
    }

    public function iniciarVisita(IniciarVisitaRequest $request, string $visit): VerificationVisitResource
    {
        $data = $request->validated();

        return new VerificationVisitResource($this->verificacion->iniciarVisita(
            $visit,
            (string) $request->user()->id,
            (int) $data['lock_version'],
        ));
    }

    public function actualizarVisita(ActualizarVisitaRequest $request, string $visit): VerificationVisitResource
    {
        $data = $request->validated();

        return new VerificationVisitResource($this->verificacion->actualizarVisita(
            $visit,
            (string) $request->user()->id,
            $data,
        ));
    }

    public function finalizarVisita(FinalizarVisitaRequest $request, string $visit): VerificationVisitResource
    {
        $data = $request->validated();

        return new VerificationVisitResource($this->verificacion->finalizarVisita(
            $visit,
            (string) $request->user()->id,
            $data['resultado_fisico'],
            $data['observaciones'] ?? null,
            (int) $data['lock_version'],
        ));
    }
}
