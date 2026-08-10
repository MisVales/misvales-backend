<?php

namespace App\Services\Credito;

use App\Enums\EstadoSolicitudIncremento;
use App\Helpers\AuditHelper;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\LineaCredito;
use App\Models\OutboxEvent;
use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Support\Facades\DB;
use App\Exceptions\ExcepcionCredito;
use App\Models\CoordinatorDistributorAssignment;

class ServicioCreacionSolicitudIncremento
{
    protected CalculadorSaldoCredito $calculador;
    protected GeneradorFolioIncremento $generadorFolio;

    public function __construct(
        CalculadorSaldoCredito $calculador,
        GeneradorFolioIncremento $generadorFolio
    ) {
        $this->calculador = $calculador;
        $this->generadorFolio = $generadorFolio;
    }

    public function crear(
        string $distributorId,
        User $solicitante,
        string $requestedAmount,
        string $requestReason,
        int $lockVersion
    ): SolicitudIncrementoLinea {
        return DB::transaction(function () use (
            $distributorId,
            $solicitante,
            $requestedAmount,
            $requestReason,
            $lockVersion
        ) {
            // 1. Obtener la línea bloqueada para consistencia (snapshot confiable)
            $linea = LineaCredito::where('distributor_id', $distributorId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Validar lock_version
            if ($linea->lock_version !== $lockVersion) {
                throw new ExcepcionCredito('CREDIT_LINE_VERSION_CONFLICT', 'La línea de crédito ha sido modificada por otra operación. Por favor, recarga y vuelve a intentarlo.', 409);
            }

            // 3. Obtener anclas de autoridad vigente
            $asignacionCoordinador = CoordinatorDistributorAssignment::where('distributor_id', $distributorId)
                ->where('status', 'ACTIVE')
                ->first();

            if (!$asignacionCoordinador) {
                throw new ExcepcionCredito('CREDIT_LINE_INCONSISTENT', 'La distribuidora no tiene un coordinador activo asignado.', 400);
            }

            $scopeSucursal = UserRoleScope::where('user_id', $distributorId)
                ->where('scope_type', 'BRANCH')
                ->where('status', 'ACTIVE')
                ->first();

            if (!$scopeSucursal) {
                throw new ExcepcionCredito('CREDIT_LINE_INCONSISTENT', 'La distribuidora no tiene una sucursal activa asignada.', 400);
            }

            // 4. Calcular saldos exactos al instante
            $saldos = $this->calculador->calcular($linea->total_authorized, $linea->used_balance);

            // 5. Generar folio
            $folio = $this->generadorFolio->generar();

            // 6. Crear solicitud (sin modificar la línea y sin restricción)
            $solicitud = SolicitudIncrementoLinea::create([
                'request_number' => $folio,
                'distributor_id' => $distributorId,
                'credit_line_id' => $linea->id,
                'branch_id' => $scopeSucursal->branch_id,
                'coordinator_id' => $asignacionCoordinador->coordinator_id,
                'status' => EstadoSolicitudIncremento::REQUESTED,
                'requested_amount' => $requestedAmount,
                'recommended_amount' => null, // No se calcula el recomendado todavía
                'authorized_amount' => null,
                'line_total_at_request' => $saldos['total_authorized'],
                'used_balance_at_request' => $saldos['used_balance'],
                'available_balance_at_request' => $saldos['available_balance'],
                'request_reason' => $requestReason,
                'requested_by' => $solicitante->id,
                'requested_at' => now(),
                'lock_version' => 1,
            ]);

            // 7. Registrar auditoría de transición de estado
            $solicitud->transiciones()->create([
                'actor_id' => $solicitante->id,
                'from_status' => null,
                'to_status' => EstadoSolicitudIncremento::REQUESTED,
                'reason' => 'Creación inicial de la solicitud.',
                'created_at' => now(),
            ]);

            $payload = [
                'actor' => $solicitante->id,
                'authorizer' => null,
                'role' => 'distributor',
                'branch' => $scopeSucursal->branch_id,
                'request_id' => $solicitud->id,
                'entity_id' => $solicitud->id,
                'previous_state' => null,
                'new_state' => EstadoSolicitudIncremento::REQUESTED,
                'amounts_before' => $saldos,
                'authorized_amount' => null,
                'amounts_after' => $saldos,
                'reason' => $requestReason,
                'configuration_version' => 'v1.0.0',
                'occurred_at' => now()->toIso8601String(),
                'result' => 'SUCCESS',
            ];

            app(AuditorIncrementos::class)->registrar(
                'EV-013',
                'credit_increase_requests',
                $solicitud->id,
                $solicitud->id,
                $solicitante,
                $scopeSucursal->branch_id,
                ['previous_state' => null, 'amounts_before' => $saldos],
                ['new_state' => EstadoSolicitudIncremento::REQUESTED, 'authorized_amount' => null, 'amounts_after' => $saldos],
                $requestReason,
                'SUCCESS',
                'v1.0.0'
            );

            OutboxEvent::create([
                'event_type' => 'CreditIncreaseRequested',
                'aggregate_type' => 'credit_increase_requests',
                'aggregate_id' => $solicitud->id,
                'payload' => json_encode($payload),
                'status' => 'PENDING'
            ]);

            return $solicitud;
        });
    }
}
