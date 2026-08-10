<?php

namespace App\Services\Credito;

use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ServicioDecisionIncremento
{
    protected ServicioRegistroMovimientoCredito $registroMovimiento;
    protected ServicioActivacionRestriccionCredito $activacionRestriccion;

    public function __construct(
        ServicioRegistroMovimientoCredito $registroMovimiento,
        ServicioActivacionRestriccionCredito $activacionRestriccion
    ) {
        $this->registroMovimiento = $registroMovimiento;
        $this->activacionRestriccion = $activacionRestriccion;
    }

    public function autorizar(SolicitudIncrementoLinea $solicitud, User $gerente, string $montoAutorizado, ?string $notas = null): SolicitudIncrementoLinea
    {
        return DB::transaction(function () use ($solicitud, $gerente, $montoAutorizado, $notas) {
            if ($solicitud->status !== 'PRE_AUTHORIZED') {
                throw new \Exception("La solicitud no está en estado PRE_AUTHORIZED.");
            }

            if (bccomp($montoAutorizado, $solicitud->requested_amount, 2) > 0) {
                throw new \Exception("El importe autorizado no puede ser mayor que el solicitado.");
            }

            $linea = $solicitud->lineaCredito;

            $restriccionPendiente = $linea->restricciones()
                ->whereIn('status', ['ACTIVE', 'RESERVED'])
                ->exists();

            if ($restriccionPendiente) {
                throw new \Exception("ADR-0001: No se puede autorizar otro incremento mientras exista una restricción vigente sin consumir.");
            }

            $solicitud->update([
                'status' => 'AUTHORIZED',
                'authorized_amount' => $montoAutorizado,
                'manager_id' => $gerente->id,
                'manager_notes' => $notas,
                'resolved_at' => Carbon::now(),
            ]);

            $this->registroMovimiento->registrar(
                $linea,
                'INCREASE',
                $montoAutorizado,
                'Incremento de línea autorizado por gerente',
                $solicitud->id,
                'SolicitudIncrementoLinea'
            );

            $this->activacionRestriccion->aplicarRestriccion($linea, $montoAutorizado, $solicitud->id);

            return $solicitud;
        });
    }

    public function rechazar(SolicitudIncrementoLinea $solicitud, User $gerente, ?string $notas = null): SolicitudIncrementoLinea
    {
        return DB::transaction(function () use ($solicitud, $gerente, $notas) {
            if ($solicitud->status !== 'PRE_AUTHORIZED') {
                throw new \Exception("La solicitud no está en estado PRE_AUTHORIZED.");
            }

            $solicitud->update([
                'status' => 'REJECTED_BY_MANAGER',
                'manager_id' => $gerente->id,
                'manager_notes' => $notas,
                'resolved_at' => Carbon::now(),
            ]);

            return $solicitud;
        });
    }
}
