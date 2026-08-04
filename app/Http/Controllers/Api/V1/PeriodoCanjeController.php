<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RedemptionPeriod;
use App\Services\PeriodoCanjeServicio;
use Illuminate\Http\Request;

class PeriodoCanjeController extends Controller
{
    public function __construct(private PeriodoCanjeServicio $servicio) {}

    public function index()
    {
        return response()->json(RedemptionPeriod::orderByDesc('created_at')->get());
    }

    public function current()
    {
        $vigente = $this->servicio->resolverVigente();
        if (!$vigente) {
            return response()->json(['message' => 'El canje de puntos se encuentra actualmente cerrado.'], 404);
        }
        return response()->json($vigente);
    }

    public function store(\App\Http\Requests\PeriodoCanje\CrearPeriodoRequest $request)
    {
        $periodo = $this->servicio->crearPeriodo($request->validated(), $request->user()->id);
        return response()->json($periodo, 201);
    }

    public function show(string $id)
    {
        $periodo = RedemptionPeriod::findOrFail($id);
        return response()->json($periodo);
    }

    public function update(\App\Http\Requests\PeriodoCanje\ActualizarPeriodoRequest $request, string $id)
    {
        $periodo = RedemptionPeriod::findOrFail($id);
        try {
            $actualizado = $this->servicio->actualizarPeriodo($periodo, $request->validated());
            return response()->json($actualizado);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function publish(\App\Http\Requests\PeriodoCanje\TransicionPeriodoRequest $request, string $id)
    {
        $periodo = RedemptionPeriod::findOrFail($id);
        try {
            $publicado = $this->servicio->publicarPeriodo($periodo, $request->validated(), $request->user()->id);
            return response()->json($publicado);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function cancel(\App\Http\Requests\PeriodoCanje\TransicionPeriodoRequest $request, string $id)
    {
        $periodo = RedemptionPeriod::findOrFail($id);
        try {
            $cancelado = $this->servicio->cancelarPeriodo($periodo, $request->validated(), $request->user()->id);
            return response()->json($cancelado);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
