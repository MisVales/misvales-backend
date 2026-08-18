<?php

namespace App\Services\Credito;

use App\Enums\EstadoSolicitudIncremento;
use App\Exceptions\ExcepcionCredito;
use App\Models\SolicitudIncrementoLinea;
use App\Models\User;

class ServicioEstadoIncremento
{
    public function transicionar(
        SolicitudIncrementoLinea $solicitud,
        EstadoSolicitudIncremento $nuevoEstado,
        User $actor,
        string $motivo
    ): void {
        $estadoAnterior = $solicitud->status;

        $this->validarTransicion($estadoAnterior, $nuevoEstado);

        $solicitud->status = $nuevoEstado;

        if (in_array($nuevoEstado, [EstadoSolicitudIncremento::PREAUTHORIZED, EstadoSolicitudIncremento::REJECTED_BY_COORDINATOR])) {
            $solicitud->coordinator_decided_by = $actor->id;
            $solicitud->coordinator_decided_at = now();
            $solicitud->coordinator_reason = $motivo;
        }

        if (in_array($nuevoEstado, [EstadoSolicitudIncremento::AUTHORIZED_PARTIAL, EstadoSolicitudIncremento::AUTHORIZED_TOTAL, EstadoSolicitudIncremento::REJECTED_BY_MANAGER])) {
            $solicitud->manager_decided_by = $actor->id;
            $solicitud->manager_decided_at = now();
            $solicitud->manager_reason = $motivo;
        }

        if ($nuevoEstado === EstadoSolicitudIncremento::COMPLETED) {
            $solicitud->completed_at = now();
        }

        $solicitud->save();

        $solicitud->transiciones()->create([
            'actor_id' => $actor->id,
            'from_status' => $estadoAnterior,
            'to_status' => $nuevoEstado,
            'reason' => $motivo,
            'created_at' => now(),
        ]);
    }

    private function validarTransicion(EstadoSolicitudIncremento $estadoAnterior, EstadoSolicitudIncremento $nuevoEstado): void
    {
        $transicionesValidas = [
            EstadoSolicitudIncremento::REQUESTED->value => [
                EstadoSolicitudIncremento::REJECTED_BY_COORDINATOR,
                EstadoSolicitudIncremento::PREAUTHORIZED,
            ],
            EstadoSolicitudIncremento::PREAUTHORIZED->value => [
                EstadoSolicitudIncremento::REJECTED_BY_MANAGER,
                EstadoSolicitudIncremento::AUTHORIZED_PARTIAL,
                EstadoSolicitudIncremento::AUTHORIZED_TOTAL,
            ],
            EstadoSolicitudIncremento::AUTHORIZED_PARTIAL->value => [
                EstadoSolicitudIncremento::COMPLETED,
            ],
            EstadoSolicitudIncremento::AUTHORIZED_TOTAL->value => [
                EstadoSolicitudIncremento::COMPLETED,
            ],
        ];

        $validasDesdeAnterior = $transicionesValidas[$estadoAnterior->value] ?? [];

        if (! in_array($nuevoEstado, $validasDesdeAnterior)) {
            throw new ExcepcionCredito(
                'CREDIT_INCREASE_REQUEST_STATUS_INVALID',
                "Transición de estado no permitida: {$estadoAnterior->value} a {$nuevoEstado->value}.",
                400,
            );
        }
    }
}
