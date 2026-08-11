<?php

namespace App\Services\Credito;

use App\Enums\EstadoSolicitudIncremento;
use App\Exceptions\ConcurrencyConflictException;
use App\Helpers\AuditHelper;
use App\Models\OutboxEvent;
use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Exceptions\ExcepcionCredito;

class ServicioPreautorizacionIncremento
{
    protected ServicioEstadoIncremento $servicioEstado;

    public function __construct(ServicioEstadoIncremento $servicioEstado)
    {
        $this->servicioEstado = $servicioEstado;
    }

    public function preautorizar(
        SolicitudIncrementoLinea $solicitudId,
        User $coordinador,
        string $montoRecomendado,
        string $motivo,
        int $lockVersion
    ): SolicitudIncrementoLinea {
        return DB::transaction(function () use ($solicitudId, $coordinador, $montoRecomendado, $motivo, $lockVersion) {
            // Bloqueo pesimista de la solicitud
            $solicitud = SolicitudIncrementoLinea::where('id', $solicitudId->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($solicitud->lock_version !== $lockVersion) {
                throw new ExcepcionCredito('CREDIT_INCREASE_REQUEST_VERSION_CONFLICT', 'La solicitud ha sido modificada por otra operación. Por favor, recarga y vuelve a intentarlo.', 409);
            }

            if ($solicitud->status !== EstadoSolicitudIncremento::REQUESTED) {
                throw new ExcepcionCredito('CREDIT_INCREASE_REQUEST_STATUS_INVALID', 'Solo se pueden preautorizar solicitudes en estado REQUESTED.', 400);
            }

            // Validar que monto recomendado <= solicitado
            if (bccomp($montoRecomendado, '0.0000', 4) <= 0 || bccomp($montoRecomendado, $solicitud->requested_amount, 4) > 0) {
                throw new ExcepcionCredito('CREDIT_INCREASE_RECOMMENDATION_INVALID', 'El importe recomendado no puede ser mayor al solicitado.', 400);
            }

            $solicitud->recommended_amount = $montoRecomendado;
            
            // La máquina de estados validará, cambiará status y grabará fechas y autor/motivo
            $this->servicioEstado->transicionar(
                $solicitud,
                EstadoSolicitudIncremento::PREAUTHORIZED,
                $coordinador,
                $motivo
            );

            $payload = [
                'actor' => $coordinador->id,
                'authorizer' => $coordinador->id,
                'role' => 'coordinator',
                'branch' => $solicitud->branch_id,
                'request_id' => $solicitud->id,
                'entity_id' => $solicitud->id,
                'previous_state' => EstadoSolicitudIncremento::REQUESTED,
                'new_state' => EstadoSolicitudIncremento::PREAUTHORIZED,
                'amounts_before' => null,
                'authorized_amount' => $montoRecomendado,
                'amounts_after' => null,
                'reason' => $motivo,
                'configuration_version' => null,
                'occurred_at' => now()->toIso8601String(),
                'result' => 'SUCCESS',
            ];

            app(AuditorIncrementos::class)->registrar(
                'EV-014',
                'credit_increase_requests',
                $solicitud->id,
                $solicitud->id,
                $coordinador,
                $solicitud->branch_id,
                ['previous_state' => EstadoSolicitudIncremento::REQUESTED],
                ['new_state' => EstadoSolicitudIncremento::PREAUTHORIZED, 'authorized_amount' => $montoRecomendado],
                $motivo,
                'SUCCESS'
            );

            OutboxEvent::create([
                'event_type' => 'CreditIncreasePreauthorized',
                'aggregate_type' => 'credit_increase_requests',
                'aggregate_id' => $solicitud->id,
                'payload' => json_encode($payload),
                'status' => 'PENDING'
            ]);

            // Incrementar lock_version de la solicitud
            $solicitud->increment('lock_version');

            return $solicitud;
        });
    }

    public function rechazarOperativamente(
        SolicitudIncrementoLinea $solicitudId,
        User $coordinador,
        string $motivo,
        int $lockVersion
    ): SolicitudIncrementoLinea {
        return DB::transaction(function () use ($solicitudId, $coordinador, $motivo, $lockVersion) {
            $solicitud = SolicitudIncrementoLinea::where('id', $solicitudId->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($solicitud->lock_version !== $lockVersion) {
                throw new ExcepcionCredito('CREDIT_INCREASE_REQUEST_VERSION_CONFLICT', 'La solicitud ha sido modificada por otra operación. Por favor, recarga y vuelve a intentarlo.', 409);
            }

            if ($solicitud->status !== EstadoSolicitudIncremento::REQUESTED) {
                throw new ExcepcionCredito('CREDIT_INCREASE_REQUEST_STATUS_INVALID', 'Solo se pueden rechazar solicitudes en estado REQUESTED.', 400);
            }

            $this->servicioEstado->transicionar(
                $solicitud,
                EstadoSolicitudIncremento::REJECTED_BY_COORDINATOR,
                $coordinador,
                $motivo
            );

            $payload = [
                'actor' => $coordinador->id,
                'authorizer' => $coordinador->id,
                'role' => 'coordinator',
                'branch' => $solicitud->branch_id,
                'request_id' => $solicitud->id,
                'entity_id' => $solicitud->id,
                'previous_state' => EstadoSolicitudIncremento::REQUESTED,
                'new_state' => EstadoSolicitudIncremento::REJECTED_BY_COORDINATOR,
                'amounts_before' => null,
                'authorized_amount' => null,
                'amounts_after' => null,
                'reason' => $motivo,
                'configuration_version' => null,
                'occurred_at' => now()->toIso8601String(),
                'result' => 'SUCCESS',
            ];

            app(AuditorIncrementos::class)->registrar(
                'EV-019',
                'credit_increase_requests',
                $solicitud->id,
                $solicitud->id,
                $coordinador,
                $solicitud->branch_id,
                ['previous_state' => EstadoSolicitudIncremento::REQUESTED],
                ['new_state' => EstadoSolicitudIncremento::REJECTED_BY_COORDINATOR],
                $motivo,
                'SUCCESS'
            );

            OutboxEvent::create([
                'event_type' => 'CreditIncreaseRejectedByCoordinator',
                'aggregate_type' => 'credit_increase_requests',
                'aggregate_id' => $solicitud->id,
                'payload' => json_encode($payload),
                'status' => 'PENDING'
            ]);

            $solicitud->increment('lock_version');

            return $solicitud;
        });
    }
}
