<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Credito\CrearSolicitudIncrementoRequest;
use App\Http\Requests\Api\V1\Credito\PreautorizarIncrementoRequest;
use App\Http\Requests\Api\V1\Credito\RechazarIncrementoCoordinadorRequest;
use App\Http\Requests\Api\V1\Credito\DecidirIncrementoGerenteRequest;
use App\Http\Resources\Api\V1\Credito\SolicitudIncrementoDetalleResource;
use App\Http\Resources\Api\V1\Credito\SolicitudIncrementoResource;
use App\Models\LineaCredito;
use App\Models\SolicitudIncrementoLinea;
use App\Services\Credito\ServicioSolicitudIncremento;
use App\Services\Credito\ServicioPreautorizacionIncremento;
use App\Services\Credito\ServicioDecisionIncremento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SolicitudIncrementoLineaController extends Controller
{
    protected ServicioSolicitudIncremento $servicioSolicitud;
    protected ServicioPreautorizacionIncremento $servicioPreautorizacion;
    protected ServicioDecisionIncremento $servicioDecision;

    public function __construct(
        ServicioSolicitudIncremento $servicioSolicitud,
        ServicioPreautorizacionIncremento $servicioPreautorizacion,
        ServicioDecisionIncremento $servicioDecision
    ) {
        $this->servicioSolicitud = $servicioSolicitud;
        $this->servicioPreautorizacion = $servicioPreautorizacion;
        $this->servicioDecision = $servicioDecision;
    }

    public function index(Request $request, LineaCredito $linea)
    {
        Gate::authorize('view', $linea);

        $solicitudes = $linea->solicitudesIncremento()->latest()->paginate($request->query('per_page', 15));
        
        return SolicitudIncrementoResource::collection($solicitudes);
    }

    public function store(CrearSolicitudIncrementoRequest $request, LineaCredito $linea)
    {
        Gate::authorize('create', SolicitudIncrementoLinea::class);
        Gate::authorize('view', $linea); // Ensure they own the line

        $solicitud = $this->servicioSolicitud->solicitar(
            $linea,
            $request->validated('monto_solicitado'),
            $request->validated('notas')
        );

        return new SolicitudIncrementoDetalleResource($solicitud);
    }

    public function preauthorize(PreautorizarIncrementoRequest $request, SolicitudIncrementoLinea $solicitud)
    {
        Gate::authorize('preauthorize', $solicitud);

        $solicitudActualizada = $this->servicioPreautorizacion->preautorizar(
            $solicitud,
            $request->user(),
            $request->validated('recommended_amount'),
            $request->validated('reason'),
            $request->validated('lock_version')
        );

        return new SolicitudIncrementoDetalleResource($solicitudActualizada);
    }

    public function rejectByCoordinator(RechazarIncrementoCoordinadorRequest $request, SolicitudIncrementoLinea $solicitud)
    {
        Gate::authorize('rejectByCoordinator', $solicitud);

        $solicitudActualizada = $this->servicioPreautorizacion->rechazarOperativamente(
            $solicitud,
            $request->user(),
            $request->validated('reason'),
            $request->validated('lock_version')
        );

        return new SolicitudIncrementoDetalleResource($solicitudActualizada);
    }

    public function decide(DecidirIncrementoGerenteRequest $request, string $solicitudId)
    {
        $solicitud = SolicitudIncrementoLinea::findOrFail($solicitudId);
        
        Gate::authorize('managerDecision', $solicitud);

        $user = $request->user();

        // El gerente no puede autorizar una solicitud que él haya creado como distribuidora.
        if ($solicitud->requested_by === $user->id || $solicitud->distributor_id === $user->id) {
            abort(403, "No puedes emitir una decisión sobre tu propia solicitud.");
        }

        $solicitudActualizada = $this->servicioDecision->decidir(
            $solicitud,
            $user,
            $request->validated('decision'),
            $request->validated('authorized_amount'),
            $request->validated('reason'),
            $request->validated('lock_version')
        );

        return new SolicitudIncrementoDetalleResource($solicitudActualizada);
    }
}
