<?php

namespace App\Services\Credito;

use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ServicioPreautorizacionIncremento
{
    public function preautorizar(SolicitudIncrementoLinea $solicitud, User $coordinador, string $montoRecomendado, ?string $notas = null): SolicitudIncrementoLinea
    {
        return DB::transaction(function () use ($solicitud, $coordinador, $montoRecomendado, $notas) {
            if ($solicitud->status !== 'PENDING') {
                throw new \Exception("La solicitud no está en estado PENDING.");
            }

            if (bccomp($montoRecomendado, $solicitud->requested_amount, 2) > 0) {
                throw new \Exception("El importe recomendado no puede ser mayor que el solicitado.");
            }

            $solicitud->update([
                'status' => 'PRE_AUTHORIZED',
                'recommended_amount' => $montoRecomendado,
                'coordinator_id' => $coordinador->id,
                'coordinator_notes' => $notas,
                'pre_authorized_at' => Carbon::now(),
            ]);

            return $solicitud;
        });
    }

    public function rechazarOperativamente(SolicitudIncrementoLinea $solicitud, User $coordinador, ?string $notas = null): SolicitudIncrementoLinea
    {
        return DB::transaction(function () use ($solicitud, $coordinador, $notas) {
            if ($solicitud->status !== 'PENDING') {
                throw new \Exception("La solicitud no está en estado PENDING.");
            }

            $solicitud->update([
                'status' => 'REJECTED_BY_COORDINATOR',
                'coordinator_id' => $coordinador->id,
                'coordinator_notes' => $notas,
                'resolved_at' => Carbon::now(),
            ]);

            return $solicitud;
        });
    }
}
