<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PeriodoCanje\ActualizarPeriodoRequest;
use App\Http\Requests\PeriodoCanje\CrearPeriodoRequest;
use App\Http\Requests\PeriodoCanje\TransicionPeriodoRequest;
use App\Models\RedemptionPeriod;
use App\Services\PeriodoCanjeServicio;
use Illuminate\Support\Facades\Gate;

class PeriodoCanjeController extends Controller
{
    public function __construct(private PeriodoCanjeServicio $servicio) {}

    public function index()
    {
        Gate::authorize('viewAny', RedemptionPeriod::class);

        return response()->json(RedemptionPeriod::orderByDesc('created_at')->get());
    }

    public function current()
    {
        Gate::authorize('viewAny', RedemptionPeriod::class);
        $vigente = $this->servicio->resolverVigente();
        if (! $vigente) {
            return response()->json(['message' => 'El canje de puntos se encuentra actualmente cerrado.'], 404);
        }

        return response()->json($vigente);
    }

    public function store(CrearPeriodoRequest $request)
    {
        Gate::authorize('create', RedemptionPeriod::class);
        $periodo = $this->servicio->crearPeriodo($request->validated(), $request->user()->id);

        return response()->json($periodo, 201);
    }

    public function show(string $id)
    {
        $periodo = RedemptionPeriod::findOrFail($id);
        Gate::authorize('view', $periodo);

        return response()->json($periodo);
    }

    public function update(ActualizarPeriodoRequest $request, string $id)
    {
        $periodo = RedemptionPeriod::findOrFail($id);
        Gate::authorize('update', $periodo);
        try {
            $actualizado = $this->servicio->actualizarPeriodo($periodo, $request->validated());

            return response()->json($actualizado);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function publish(TransicionPeriodoRequest $request, string $id)
    {
        $periodo = RedemptionPeriod::findOrFail($id);
        Gate::authorize('publish', $periodo);
        try {
            $publicado = $this->servicio->publicarPeriodo($periodo, $request->validated(), $request->user()->id);

            return response()->json($publicado);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function cancel(TransicionPeriodoRequest $request, string $id)
    {
        $periodo = RedemptionPeriod::findOrFail($id);
        Gate::authorize('update', $periodo);
        try {
            $cancelado = $this->servicio->cancelarPeriodo($periodo, $request->validated(), $request->user()->id);

            return response()->json($cancelado);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
