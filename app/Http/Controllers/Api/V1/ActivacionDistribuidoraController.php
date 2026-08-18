<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ExcepcionDistribuidora;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distribuidora\ActivarDistribuidoraRequest;
use App\Http\Resources\Api\V1\Distribuidora\DistribuidoraDetalleResource;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\OutboxEvent;
use App\Services\Distribuidora\AuditorDistribuidora;
use App\Services\Distribuidora\ServicioActivacionDistribuidora;
use Illuminate\Support\Facades\Gate;

class ActivacionDistribuidoraController extends Controller
{
    public function store(
        ActivarDistribuidoraRequest $request,
        DistributorApplication $application,
        ServicioActivacionDistribuidora $servicio,
        AuditorDistribuidora $auditor,
    ): DistribuidoraDetalleResource {
        if (Gate::denies('activate', [Distribuidora::class, $application])) {
            throw new ExcepcionDistribuidora('AUTH_SCOPE_DENIED', 'La solicitud no está dentro del alcance autorizado.', 403);
        }

        try {
            return new DistribuidoraDetalleResource($servicio->activar(
                $application->id,
                $request->validated('category_version_id'),
                $request->user(),
            ));
        } catch (ExcepcionDistribuidora $excepcion) {
            OutboxEvent::create([
                'event_type' => 'DISTRIBUTOR_ACTIVATION_FAILED',
                'payload' => [
                    'application_id' => $application->id,
                    'branch_id' => $application->branch_id,
                    'error_code' => $excepcion->errorCode,
                ],
                'status' => 'PENDING',
            ]);
            $auditor->registrar(
                'DISTRIBUTOR_ACTIVATION_FAILED',
                'DistributorApplication',
                $application->id,
                $request->user(),
                $application->branch_id,
                resultado: 'FAILED',
                motivo: $excepcion->errorCode,
            );

            throw $excepcion;
        }
    }
}
