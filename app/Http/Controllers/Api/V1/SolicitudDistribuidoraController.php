<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\ActualizarSolicitudDistribuidoraRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\CrearSolicitudDistribuidoraRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\EliminarRegistroSolicitudRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\EnlistarSolicitudesRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\EnviarSolicitudRevisionRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\GuardarCreditoComercialRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\GuardarDatosPersonalesRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\GuardarDomicilioRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\GuardarEmpleoRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\GuardarFamiliarRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\GuardarPatrimonioRequest;
use App\Http\Requests\Api\V1\SolicitudDistribuidora\GuardarVehiculoRequest;
use App\Http\Resources\Api\V1\SolicitudDistribuidora\CreditoComercialSolicitudResource;
use App\Http\Resources\Api\V1\SolicitudDistribuidora\DatosPersonalesSolicitudResource;
use App\Http\Resources\Api\V1\SolicitudDistribuidora\DomicilioSolicitudResource;
use App\Http\Resources\Api\V1\SolicitudDistribuidora\EmpleoSolicitudResource;
use App\Http\Resources\Api\V1\SolicitudDistribuidora\FamiliarSolicitudResource;
use App\Http\Resources\Api\V1\SolicitudDistribuidora\PatrimonioSolicitudResource;
use App\Http\Resources\Api\V1\SolicitudDistribuidora\SolicitudDistribuidoraDetalleResource;
use App\Http\Resources\Api\V1\SolicitudDistribuidora\SolicitudDistribuidoraResource;
use App\Http\Resources\Api\V1\SolicitudDistribuidora\VehiculoSolicitudResource;
use App\Models\CreditoComercialSolicitud;
use App\Models\DomicilioSolicitud;
use App\Models\EmpleoSolicitud;
use App\Models\FamiliarSolicitud;
use App\Models\PatrimonioSolicitud;
use App\Models\SolicitudDistribuidora;
use App\Models\VehiculoSolicitud;
use App\Services\SolicitudDistribuidora\ServicioSolicitudDistribuidora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class SolicitudDistribuidoraController extends Controller
{
    public function index(EnlistarSolicitudesRequest $request, ServicioSolicitudDistribuidora $servicio): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SolicitudDistribuidora::class);

        return SolicitudDistribuidoraResource::collection(
            $servicio->listarSolicitudes($request->user(), $request->validated()),
        );
    }

    public function store(CrearSolicitudDistribuidoraRequest $request, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('create', SolicitudDistribuidora::class);
        $solicitud = $servicio->crearSolicitud($request->user(), $request->validated());

        return (new SolicitudDistribuidoraResource($solicitud))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SolicitudDistribuidora $application, Request $request, ServicioSolicitudDistribuidora $servicio): SolicitudDistribuidoraDetalleResource
    {
        Gate::authorize('view', $application);

        return new SolicitudDistribuidoraDetalleResource(
            $servicio->consultarSolicitud($request->user(), $application),
        )->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ]);
    }

    public function update(ActualizarSolicitudDistribuidoraRequest $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): SolicitudDistribuidoraResource
    {
        Gate::authorize('update', $application);

        return new SolicitudDistribuidoraResource(
            $servicio->actualizarSolicitud($request->user(), $application, $request->validated()),
        )->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ]);
    }

    public function guardarDatosPersonales(GuardarDatosPersonalesRequest $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);

        return (new DatosPersonalesSolicitudResource(
            $servicio->guardarDatosPersonales($request->user(), $application, $request->validated()),
        ))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ])->response()->setStatusCode(201);
    }

    public function listarDomicilios(Request $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): AnonymousResourceCollection
    {
        Gate::authorize('view', $application);

        return DomicilioSolicitudResource::collection(
            $servicio->listarDomicilios($request->user(), $application),
        );
    }

    public function crearDomicilio(GuardarDomicilioRequest $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);

        return (new DomicilioSolicitudResource(
            $servicio->guardarDomicilio($request->user(), $application, $request->validated()),
        ))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ])->response()->setStatusCode(201);
    }

    public function actualizarDomicilio(GuardarDomicilioRequest $request, SolicitudDistribuidora $application, DomicilioSolicitud $residence, ServicioSolicitudDistribuidora $servicio): DomicilioSolicitudResource
    {
        Gate::authorize('update', $application);

        return new DomicilioSolicitudResource(
            $servicio->guardarDomicilio($request->user(), $application, $request->validated(), $residence),
        )->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ]);
    }

    public function eliminarDomicilio(EliminarRegistroSolicitudRequest $request, SolicitudDistribuidora $application, DomicilioSolicitud $residence, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);
        $servicio->eliminarDomicilio($request->user(), $application, $residence, $request->integer('lock_version'));

        return response()->json(null, 204);
    }

    public function enviarARevision(EnviarSolicitudRevisionRequest $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): SolicitudDistribuidoraResource
    {
        Gate::authorize('submit', $application);

        return new SolicitudDistribuidoraResource(
            $servicio->enviarARevision($request->user(), $application, $request->integer('lock_version')),
        )->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ]);
    }

    public function listarFamiliares(Request $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): AnonymousResourceCollection
    {
        Gate::authorize('view', $application);

        return FamiliarSolicitudResource::collection($servicio->listarFamiliares($request->user(), $application));
    }

    public function crearFamiliar(GuardarFamiliarRequest $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);

        return (new FamiliarSolicitudResource($servicio->guardarFamiliar($request->user(), $application, $request->validated())))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ])->response()->setStatusCode(201);
    }

    public function actualizarFamiliar(GuardarFamiliarRequest $request, SolicitudDistribuidora $application, FamiliarSolicitud $member, ServicioSolicitudDistribuidora $servicio): FamiliarSolicitudResource
    {
        Gate::authorize('update', $application);

        return new FamiliarSolicitudResource($servicio->guardarFamiliar($request->user(), $application, $request->validated(), $member))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ]);
    }

    public function eliminarFamiliar(EliminarRegistroSolicitudRequest $request, SolicitudDistribuidora $application, FamiliarSolicitud $member, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);
        $servicio->eliminarRegistroDeBorrador($request->user(), $application, $member, $request->integer('lock_version'));

        return response()->json(null, 204);
    }

    public function listarVehiculos(Request $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): AnonymousResourceCollection
    {
        Gate::authorize('view', $application);

        return VehiculoSolicitudResource::collection($servicio->listarVehiculos($request->user(), $application));
    }

    public function crearVehiculo(GuardarVehiculoRequest $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);

        return (new VehiculoSolicitudResource($servicio->guardarVehiculo($request->user(), $application, $request->validated())))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ])->response()->setStatusCode(201);
    }

    public function actualizarVehiculo(GuardarVehiculoRequest $request, SolicitudDistribuidora $application, VehiculoSolicitud $vehicle, ServicioSolicitudDistribuidora $servicio): VehiculoSolicitudResource
    {
        Gate::authorize('update', $application);

        return new VehiculoSolicitudResource($servicio->guardarVehiculo($request->user(), $application, $request->validated(), $vehicle))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ]);
    }

    public function eliminarVehiculo(EliminarRegistroSolicitudRequest $request, SolicitudDistribuidora $application, VehiculoSolicitud $vehicle, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);
        $servicio->eliminarRegistroDeBorrador($request->user(), $application, $vehicle, $request->integer('lock_version'));

        return response()->json(null, 204);
    }

    public function listarPatrimonio(Request $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): AnonymousResourceCollection
    {
        Gate::authorize('view', $application);

        return PatrimonioSolicitudResource::collection($servicio->listarPatrimonio($request->user(), $application));
    }

    public function crearPatrimonio(GuardarPatrimonioRequest $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);

        return (new PatrimonioSolicitudResource($servicio->guardarPatrimonio($request->user(), $application, $request->validated())))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ])->response()->setStatusCode(201);
    }

    public function actualizarPatrimonio(GuardarPatrimonioRequest $request, SolicitudDistribuidora $application, PatrimonioSolicitud $entry, ServicioSolicitudDistribuidora $servicio): PatrimonioSolicitudResource
    {
        Gate::authorize('update', $application);

        return new PatrimonioSolicitudResource($servicio->guardarPatrimonio($request->user(), $application, $request->validated(), $entry))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ]);
    }

    public function eliminarPatrimonio(EliminarRegistroSolicitudRequest $request, SolicitudDistribuidora $application, PatrimonioSolicitud $entry, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);
        $servicio->eliminarRegistroDeBorrador($request->user(), $application, $entry, $request->integer('lock_version'));

        return response()->json(null, 204);
    }

    public function listarEmpleos(Request $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): AnonymousResourceCollection
    {
        Gate::authorize('view', $application);

        return EmpleoSolicitudResource::collection($servicio->listarEmpleos($request->user(), $application));
    }

    public function crearEmpleo(GuardarEmpleoRequest $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);

        return (new EmpleoSolicitudResource($servicio->guardarEmpleo($request->user(), $application, $request->validated())))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ])->response()->setStatusCode(201);
    }

    public function actualizarEmpleo(GuardarEmpleoRequest $request, SolicitudDistribuidora $application, EmpleoSolicitud $employment, ServicioSolicitudDistribuidora $servicio): EmpleoSolicitudResource
    {
        Gate::authorize('update', $application);

        return new EmpleoSolicitudResource($servicio->guardarEmpleo($request->user(), $application, $request->validated(), $employment))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ]);
    }

    public function eliminarEmpleo(EliminarRegistroSolicitudRequest $request, SolicitudDistribuidora $application, EmpleoSolicitud $employment, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);
        $servicio->eliminarRegistroDeBorrador($request->user(), $application, $employment, $request->integer('lock_version'));

        return response()->json(null, 204);
    }

    public function listarCreditosComerciales(Request $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): AnonymousResourceCollection
    {
        Gate::authorize('view', $application);

        return CreditoComercialSolicitudResource::collection($servicio->listarCreditosComerciales($request->user(), $application));
    }

    public function crearCreditoComercial(GuardarCreditoComercialRequest $request, SolicitudDistribuidora $application, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);

        return (new CreditoComercialSolicitudResource($servicio->guardarCreditoComercial($request->user(), $application, $request->validated())))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ])->response()->setStatusCode(201);
    }

    public function actualizarCreditoComercial(GuardarCreditoComercialRequest $request, SolicitudDistribuidora $application, CreditoComercialSolicitud $credit, ServicioSolicitudDistribuidora $servicio): CreditoComercialSolicitudResource
    {
        Gate::authorize('update', $application);

        return new CreditoComercialSolicitudResource($servicio->guardarCreditoComercial($request->user(), $application, $request->validated(), $credit))->additional([
            'completion' => $servicio->calcularCompletitud($application->fresh()),
            'lock_version' => $application->fresh()->lock_version,
            'saved_at' => now()->toISOString(),
        ]);
    }

    public function eliminarCreditoComercial(EliminarRegistroSolicitudRequest $request, SolicitudDistribuidora $application, CreditoComercialSolicitud $credit, ServicioSolicitudDistribuidora $servicio): JsonResponse
    {
        Gate::authorize('update', $application);
        $servicio->eliminarRegistroDeBorrador($request->user(), $application, $credit, $request->integer('lock_version'));

        return response()->json(null, 204);
    }
}
