<?php

namespace App\Services\Credito;

use App\Enums\EstadoSolicitudIncremento;
use App\Helpers\AuditHelper;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\OutboxEvent;
use App\Models\RestriccionUsoCredito;
use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use App\Services\ConfiguracionServicio;
use App\Enums\TipoMovimientoLineaCredito;
use Illuminate\Support\Facades\DB;
use App\Exceptions\ExcepcionCredito;

class ServicioDecisionIncremento
{
    protected ServicioEstadoIncremento $servicioEstado;
    protected CalculadorSaldoCredito $calculador;
    protected ConfiguracionServicio $configuracionServicio;

    public function __construct(
        ServicioEstadoIncremento $servicioEstado,
        CalculadorSaldoCredito $calculador,
        ConfiguracionServicio $configuracionServicio
    ) {
        $this->servicioEstado = $servicioEstado;
        $this->calculador = $calculador;
        $this->configuracionServicio = $configuracionServicio;
    }

    public function decidir(
        SolicitudIncrementoLinea $solicitudId,
        User $gerente,
        string $decision,
        ?string $authorizedAmount,
        string $motivo,
        int $lockVersion
    ): SolicitudIncrementoLinea {
        return DB::transaction(function () use ($solicitudId, $gerente, $decision, $authorizedAmount, $motivo, $lockVersion) {
            $solicitud = SolicitudIncrementoLinea::where('id', $solicitudId->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($solicitud->lock_version !== $lockVersion) {
                throw new ExcepcionCredito('CREDIT_INCREASE_REQUEST_VERSION_CONFLICT', 'La solicitud ha sido modificada por otra operación. Por favor, recarga y vuelve a intentarlo.', 409);
            }

            if ($solicitud->status !== EstadoSolicitudIncremento::PREAUTHORIZED) {
                throw new ExcepcionCredito('CREDIT_INCREASE_REQUEST_STATUS_INVALID', 'Solo se pueden decidir solicitudes en estado PREAUTHORIZED.', 400);
            }

            if ($gerente->id === $solicitud->requested_by || $gerente->id === $solicitud->coordinator_decided_by) {
                throw new ExcepcionCredito('SEPARATION_OF_DUTIES_VIOLATION', 'No puedes autorizar una solicitud en la que participaste previamente.', 403);
            }

            $nuevoEstado = null;
            $montoFinal = null;
            $versionConfiguracion = null;

            if ($decision === 'APPROVE_REQUESTED') {
                $nuevoEstado = EstadoSolicitudIncremento::AUTHORIZED_TOTAL;
                $montoFinal = $solicitud->requested_amount;
            } elseif ($decision === 'APPROVE_LOWER') {
                $nuevoEstado = EstadoSolicitudIncremento::AUTHORIZED_PARTIAL;
                if ($authorizedAmount === null || bccomp($authorizedAmount, '0.0000', 4) <= 0 || bccomp($authorizedAmount, $solicitud->requested_amount, 4) >= 0) {
                    throw new ExcepcionCredito('CREDIT_INCREASE_AUTHORIZED_AMOUNT_INVALID', 'El importe autorizado debe ser menor al solicitado en una aprobación parcial.', 400);
                }
                $montoFinal = $authorizedAmount;
            } elseif ($decision === 'REJECT') {
                $nuevoEstado = EstadoSolicitudIncremento::REJECTED_BY_MANAGER;
                $montoFinal = null;
            } else {
                throw new ExcepcionCredito('CREDIT_INCREASE_REQUEST_STATUS_INVALID', 'Decisión no reconocida.', 400);
            }

            $solicitud->authorized_amount = $montoFinal;
            $solicitud->manager_decision = $decision;

            // Si es aprobatorio, aplicamos los cambios a la línea
            if ($nuevoEstado === EstadoSolicitudIncremento::AUTHORIZED_TOTAL || $nuevoEstado === EstadoSolicitudIncremento::AUTHORIZED_PARTIAL) {
                // Bloqueo pesimista de la línea
                $linea = LineaCredito::where('id', $solicitud->credit_line_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Validar que no exista otra restricción ACTIVE o RESERVED (Bloqueo pesimista para evitar concurrencia en la misma DB transaction)
                $restriccionActiva = RestriccionUsoCredito::where('credit_line_id', $linea->id)
                    ->whereIn('status', ['ACTIVE', 'RESERVED'])
                    ->lockForUpdate()
                    ->exists();

                if ($restriccionActiva) {
                    throw new ExcepcionCredito('CREDIT_USAGE_RESTRICTION_ACTIVE', 'La línea de crédito ya tiene una restricción activa. No se puede aplicar el incremento.', 409);
                }

                // Recalcular saldos actuales
                $saldosActuales = $this->calculador->calcular($linea->total_authorized, $linea->used_balance);
                $totalAuthorizedBefore = $saldosActuales['total_authorized'];
                $usedBalanceBefore = $saldosActuales['used_balance'];

                // Calcular el nuevo total autorizado
                $newTotalAuthorized = bcadd($totalAuthorizedBefore, $montoFinal, 4);
                $usedBalanceAfter = $usedBalanceBefore;

                // Actualizar línea
                $linea->total_authorized = $newTotalAuthorized;
                $linea->lock_version++;
                $linea->save();

                // Crear el movimiento
                $secuencia = MovimientoLineaCredito::where('credit_line_id', $linea->id)->max('sequence') + 1;
                
                MovimientoLineaCredito::create([
                    'credit_line_id' => $linea->id,
                    'distributor_id' => $linea->distributor_id,
                    'sequence' => $secuencia,
                    'type' => TipoMovimientoLineaCredito::INCREASE,
                    'amount' => $montoFinal,
                    'total_authorized_before' => $totalAuthorizedBefore,
                    'total_authorized_after' => $newTotalAuthorized,
                    'used_balance_before' => $usedBalanceBefore,
                    'used_balance_after' => $usedBalanceAfter,
                    'source_type' => 'CREDIT_INCREASE_REQUEST',
                    'source_id' => $solicitud->id,
                    'reason' => 'Incremento de crédito autorizado por gerente.',
                    'performed_by' => $solicitud->requested_by,
                    'authorized_by' => $gerente->id,
                    'idempotency_key' => 'credit-increase:'.$solicitud->id,
                    'occurred_at' => now(),
                ]);

                // Resolver tolerancia y crear restricción
                $configuracionTolerancia = $this->configuracionServicio->resolver('CREDIT_TOLERANCE_AMOUNT');
                $versionConfiguracion = (string) $configuracionTolerancia['version_id'];
                
                $restriccion = RestriccionUsoCredito::create([
                    'credit_line_id' => $linea->id,
                    'distributor_id' => $linea->distributor_id,
                    'type' => 'POST_INCREASE_50_PERCENT',
                    'status' => 'ACTIVE',
                    'base_total' => $newTotalAuthorized,
                    'tolerance_amount' => $configuracionTolerancia['value'],
                    'configuration_version_id' => $configuracionTolerancia['version_id'],
                    'source_type' => 'CREDIT_INCREASE_REQUEST',
                    'source_id' => $solicitud->id,
                    'activated_at' => now(),
                    'created_by' => $gerente->id,
                ]);

                // Vincular restricción a la solicitud (si la migración lo permite)
                // Wait, el requerimiento dice: "Vincular la restricción con la solicitud."
                $solicitud->restriction_id = $restriccion->id;

                $payloadRestriccion = [
                    'actor' => $gerente->id,
                    'authorizer' => $gerente->id,
                    'role' => 'manager',
                    'branch' => $solicitud->branch_id,
                    'request_id' => $solicitud->id,
                    'entity_id' => $restriccion->id,
                    'previous_state' => null,
                    'new_state' => 'ACTIVE',
                    'amounts_before' => null,
                    'authorized_amount' => null,
                    'amounts_after' => null,
                    'reason' => 'Activación de restricción 50% post incremento',
                    'configuration_version' => $versionConfiguracion,
                    'occurred_at' => now()->toIso8601String(),
                    'result' => 'SUCCESS',
                ];

                app(AuditorIncrementos::class)->registrar(
                    'EV-018',
                    'credit_usage_restrictions',
                    $restriccion->id,
                    $solicitud->id,
                    $gerente,
                    $solicitud->branch_id,
                    [],
                    ['status' => 'ACTIVE', 'base_total' => $newTotalAuthorized, 'tolerance_amount' => $configuracionTolerancia['value']],
                    'Activación de restricción 50% post incremento',
                    'SUCCESS',
                    $versionConfiguracion
                );

                OutboxEvent::create([
                    'event_type' => 'CreditUsageRestrictionActivated',
                    'aggregate_type' => 'credit_usage_restrictions',
                    'aggregate_id' => $restriccion->id,
                    'payload' => json_encode($payloadRestriccion),
                    'status' => 'PENDING'
                ]);
            }

            // Transicionar estado
            $this->servicioEstado->transicionar(
                $solicitud,
                $nuevoEstado,
                $gerente,
                $motivo
            );

            // Estampar autoridad gerencial
            $solicitud->manager_decided_by = $gerente->id;
            $solicitud->manager_decided_at = now();
            $solicitud->save();

            // Auditoría
            $eventCode = match($decision) {
                'APPROVE_REQUESTED' => 'EV-015',
                'APPROVE_LOWER' => 'EV-016',
                'REJECT' => 'EV-017'
            };
            
            $outboxEventName = match($decision) {
                'APPROVE_REQUESTED' => 'CreditIncreaseAuthorizedFull',
                'APPROVE_LOWER' => 'CreditIncreaseAuthorizedPartial',
                'REJECT' => 'CreditIncreaseRejectedByManager'
            };

            $payload = [
                'actor' => $gerente->id,
                'authorizer' => $gerente->id,
                'role' => 'manager',
                'branch' => $solicitud->branch_id,
                'request_id' => $solicitud->id,
                'entity_id' => $solicitud->id,
                'previous_state' => EstadoSolicitudIncremento::PREAUTHORIZED,
                'new_state' => $nuevoEstado,
                'amounts_before' => $saldosActuales ?? null,
                'authorized_amount' => $montoFinal,
                'amounts_after' => isset($newTotalAuthorized) ? [
                    'total_authorized' => (string) $newTotalAuthorized,
                    'used_balance' => (string) $usedBalanceAfter,
                    'available_balance' => bcsub((string) $newTotalAuthorized, (string) $usedBalanceAfter, 4)
                ] : null,
                'reason' => $motivo,
                'configuration_version' => $versionConfiguracion,
                'occurred_at' => now()->toIso8601String(),
                'result' => 'SUCCESS',
            ];

            app(AuditorIncrementos::class)->registrar(
                $eventCode,
                'credit_increase_requests',
                $solicitud->id,
                $solicitud->id,
                $gerente,
                $solicitud->branch_id,
                ['previous_state' => EstadoSolicitudIncremento::PREAUTHORIZED, 'amounts_before' => $payload['amounts_before']],
                ['new_state' => $nuevoEstado, 'authorized_amount' => $montoFinal, 'amounts_after' => $payload['amounts_after']],
                $motivo,
                'SUCCESS',
                $versionConfiguracion
            );

            OutboxEvent::create([
                'event_type' => $outboxEventName,
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
