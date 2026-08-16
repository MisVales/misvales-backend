<?php

namespace App\Http\Controllers\Api\V1\Credito;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Credito\CrearSolicitudIncrementoRequest;
use App\Http\Resources\Api\V1\Credito\SolicitudIncrementoDetalleResource;
use App\Models\Distribuidora;
use App\Services\Credito\ServicioCreacionSolicitudIncremento;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CrearSolicitudIncrementoController extends Controller
{
    protected ServicioCreacionSolicitudIncremento $servicio;

    public function __construct(ServicioCreacionSolicitudIncremento $servicio)
    {
        $this->servicio = $servicio;
    }

    public function store(CrearSolicitudIncrementoRequest $request, string $distributorId)
    {
        $user = $request->user();

        $distribuidora = Distribuidora::query()->findOrFail($distributorId);

        // Validar que la distribuidora solo solicita sobre su propia línea.
        // El identificador de la ruta pertenece a Distribuidora, no a User.
        if ($user->id !== $distribuidora->user_id) {
            throw new ModelNotFoundException; // 404 para ocultar si intentan acceder a otro ID
        }

        $solicitud = $this->servicio->crear(
            $distribuidora->id,
            $user,
            $request->validated('requested_amount'),
            $request->validated('request_reason'),
            $request->validated('lock_version')
        );

        return new SolicitudIncrementoDetalleResource($solicitud);
    }
}
