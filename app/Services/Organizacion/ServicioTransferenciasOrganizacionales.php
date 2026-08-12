<?php

namespace App\Services\Organizacion;

use App\Enums\EstadoDistribuidora;
use App\Exceptions\ExcepcionCliente;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\Cliente;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\EventoCambioOrganizacional;
use App\Models\OutboxEvent;
use App\Models\SolicitudTransferenciaCliente;
use App\Models\User;
use App\Services\Cliente\ServicioCarteraInformativa;
use Illuminate\Support\Facades\DB;

final readonly class ServicioTransferenciasOrganizacionales
{
    public function __construct(private ServicioCarteraInformativa $cartera) {}

    public function iniciar(Cliente $cliente, Distribuidora $destino, User $actor): SolicitudTransferenciaCliente
    {
        return DB::transaction(function () use ($cliente, $destino, $actor): SolicitudTransferenciaCliente {
            $asignacion = AsignacionClienteDistribuidora::query()->where('client_id', $cliente->id)->whereNull('ends_at')->lockForUpdate()->first();
            if (! $asignacion || $asignacion->distributor_id === $destino->id || $asignacion->distribuidora?->user_id !== $actor->id) {
                throw new ExcepcionCliente('CLIENT_TRANSFER_NOT_ALLOWED', 'La distribuidora origen no puede iniciar esta transferencia.', 403);
            }
            $this->distribuidoraActiva($destino);
            if (! $this->cartera->resumen($cliente->id)['is_transfer_balance_zero']) {
                throw new ExcepcionCliente('CLIENT_TRANSFER_BALANCE_NOT_ZERO', 'El saldo requerido para transferencia debe ser cero.', 409);
            }

            return SolicitudTransferenciaCliente::create([
                'client_id' => $cliente->id,
                'origin_assignment_id' => $asignacion->id,
                'origin_distributor_id' => $asignacion->distributor_id,
                'destination_distributor_id' => $destino->id,
                'origin_branch_id' => $asignacion->branch_id,
                'destination_branch_id' => $destino->branch_id,
                'status' => 'REQUESTED',
                'initiated_by' => $actor->id,
            ]);
        }, 3);
    }

    public function preaceptar(SolicitudTransferenciaCliente $solicitud, User $actor, bool $aceptar): SolicitudTransferenciaCliente
    {
        return DB::transaction(function () use ($solicitud, $actor, $aceptar): SolicitudTransferenciaCliente {
            $solicitud = $this->bloquear($solicitud, 'REQUESTED');
            $destino = Distribuidora::query()->findOrFail($solicitud->destination_distributor_id);
            if ($destino->user_id !== $actor->id) {
                throw new ExcepcionCliente('CLIENT_TRANSFER_RECEIVER_REQUIRED', 'Solo la distribuidora receptora puede responder.', 403);
            }
            $solicitud->update([
                'status' => $aceptar ? 'PREACCEPTED' : 'REJECTED_BY_RECEIVER',
                'preaccepted_by' => $actor->id,
                'preaccepted_at' => now(),
            ]);

            return $solicitud->refresh();
        }, 3);
    }

    public function decidirSalida(SolicitudTransferenciaCliente $solicitud, User $actor, bool $autorizar, string $motivo): SolicitudTransferenciaCliente
    {
        return DB::transaction(function () use ($solicitud, $actor, $autorizar, $motivo): SolicitudTransferenciaCliente {
            $solicitud = $this->bloquear($solicitud, 'PREACCEPTED');
            $asignacion = CoordinatorDistributorAssignment::query()
                ->where('distributor_id', $solicitud->origin_distributor_id)
                ->where('status', 'ACTIVE')->whereNull('valid_to')->first();
            if (! $asignacion || $asignacion->coordinator_id !== $actor->id) {
                throw new ExcepcionCliente('CLIENT_TRANSFER_ORIGIN_COORDINATOR_REQUIRED', 'Solo el coordinador origen asignado puede decidir la salida.', 403);
            }
            $solicitud->update([
                'status' => $autorizar ? 'ORIGIN_AUTHORIZED' : 'ORIGIN_REJECTED',
                'origin_decided_by' => $actor->id,
                'origin_decision_reason' => $motivo,
                'origin_decided_at' => now(),
            ]);

            return $solicitud->refresh();
        }, 3);
    }

    public function completar(SolicitudTransferenciaCliente $solicitud, User $actor): SolicitudTransferenciaCliente
    {
        return DB::transaction(function () use ($solicitud, $actor): SolicitudTransferenciaCliente {
            $solicitud = $this->bloquear($solicitud, 'ORIGIN_AUTHORIZED');
            $destino = Distribuidora::query()->lockForUpdate()->findOrFail($solicitud->destination_distributor_id);
            if ($destino->user_id !== $actor->id) {
                throw new ExcepcionCliente('CLIENT_TRANSFER_RECEIVER_REQUIRED', 'Solo la receptora puede aceptar definitivamente.', 403);
            }
            $this->distribuidoraActiva($destino);
            $actual = AsignacionClienteDistribuidora::query()->whereKey($solicitud->origin_assignment_id)->whereNull('ends_at')->lockForUpdate()->first();
            if (! $actual || ! $this->cartera->resumen($solicitud->client_id)['is_transfer_balance_zero']) {
                throw new ExcepcionCliente('CLIENT_TRANSFER_CONTEXT_CHANGED', 'La asignación o el saldo cambiaron antes de completar.', 409);
            }
            $momento = now();
            $actual->update(['ends_at' => $momento, 'reason' => 'TRANSFER_COMPLETED']);
            $nueva = AsignacionClienteDistribuidora::create([
                'client_id' => $solicitud->client_id,
                'distributor_id' => $destino->id,
                'branch_id' => $destino->branch_id,
                'starts_at' => $momento,
                'assigned_by' => $actor->id,
                'reason' => 'TRANSFER_COMPLETED',
            ]);
            $solicitud->update(['status' => 'COMPLETED', 'completed_by' => $actor->id, 'completed_at' => $momento, 'new_assignment_id' => $nueva->id]);
            $this->notificar('CLIENT_TRANSFER_COMPLETED', $solicitud->id, ['client_id' => $solicitud->client_id, 'origin_distributor_id' => $solicitud->origin_distributor_id, 'destination_distributor_id' => $destino->id]);

            return $solicitud->refresh();
        }, 3);
    }

    public function reasignarCliente(Cliente $cliente, Distribuidora $destino, User $actor, string $motivo): AsignacionClienteDistribuidora
    {
        return DB::transaction(function () use ($cliente, $destino, $actor, $motivo): AsignacionClienteDistribuidora {
            $actual = AsignacionClienteDistribuidora::query()->where('client_id', $cliente->id)->whereNull('ends_at')->lockForUpdate()->firstOrFail();
            $this->autorizarGerente($actor, $actual->branch_id);
            $this->autorizarGerente($actor, $destino->branch_id);
            $this->distribuidoraActiva($destino);
            if ($actual->distributor_id === $destino->id) {
                throw new ExcepcionCliente('CLIENT_ALREADY_ASSIGNED', 'El cliente ya pertenece al destino.', 409);
            }
            $momento = now();
            $antes = $actual->only(['id', 'client_id', 'distributor_id', 'branch_id', 'starts_at']);
            $actual->update(['ends_at' => $momento, 'reason' => $motivo]);
            $nueva = AsignacionClienteDistribuidora::create(['client_id' => $cliente->id, 'distributor_id' => $destino->id, 'branch_id' => $destino->branch_id, 'starts_at' => $momento, 'assigned_by' => $actor->id, 'reason' => $motivo]);
            $this->evento('CLIENT_ADMIN_REASSIGNMENT', $cliente->id, $actual->branch_id, $destino->branch_id, $actor, $motivo, $antes, $nueva->toArray());

            return $nueva;
        }, 3);
    }

    public function cambiarSucursal(Distribuidora $distribuidora, string $sucursalDestino, User $coordinadorDestino, User $actor, string $motivo): Distribuidora
    {
        return DB::transaction(function () use ($distribuidora, $sucursalDestino, $coordinadorDestino, $actor, $motivo): Distribuidora {
            $distribuidora = Distribuidora::query()->lockForUpdate()->findOrFail($distribuidora->id);
            $this->autorizarGerente($actor, $distribuidora->branch_id);
            $this->autorizarGerente($actor, $sucursalDestino);
            if ($distribuidora->asignacionesClientes()->whereNull('ends_at')->exists()) {
                throw new ExcepcionCliente('DISTRIBUTOR_HAS_ASSIGNED_CLIENTS', 'Los clientes deben reasignarse antes del cambio de sucursal.', 409);
            }
            $this->coordinadorValido($coordinadorDestino, $sucursalDestino);
            $actual = CoordinatorDistributorAssignment::query()->where('distributor_id', $distribuidora->id)->where('status', 'ACTIVE')->whereNull('valid_to')->lockForUpdate()->first();
            if ($distribuidora->status === EstadoDistribuidora::ACTIVA && ! $actual) {
                throw new ExcepcionCliente('ACTIVE_DISTRIBUTOR_WITHOUT_COORDINATOR', 'La distribuidora activa no tiene coordinador vigente.', 409);
            }
            $antes = ['branch_id' => $distribuidora->branch_id, 'coordinator_id' => $actual?->coordinator_id];
            $momento = now();
            $actual?->update(['status' => 'REASSIGNED', 'valid_to' => $momento, 'ended_by' => $actor->id, 'end_reason' => $motivo, 'lock_version' => ($actual->lock_version ?? 0) + 1]);
            CoordinatorDistributorAssignment::create(['coordinator_id' => $coordinadorDestino->id, 'distributor_id' => $distribuidora->id, 'branch_id' => $sucursalDestino, 'valid_from' => $momento, 'status' => 'ACTIVE', 'assigned_by' => $actor->id, 'assignment_reason' => $motivo]);
            $origen = $distribuidora->branch_id;
            $distribuidora->update(['branch_id' => $sucursalDestino]);
            $this->evento('DISTRIBUTOR_BRANCH_CHANGE', $distribuidora->id, $origen, $sucursalDestino, $actor, $motivo, $antes, ['branch_id' => $sucursalDestino, 'coordinator_id' => $coordinadorDestino->id]);

            return $distribuidora->refresh();
        }, 3);
    }

    public function cambiarCoordinador(Distribuidora $distribuidora, User $destino, User $actor, string $motivo): CoordinatorDistributorAssignment
    {
        return DB::transaction(function () use ($distribuidora, $destino, $actor, $motivo): CoordinatorDistributorAssignment {
            $distribuidora = Distribuidora::query()->lockForUpdate()->findOrFail($distribuidora->id);
            $this->autorizarGerente($actor, $distribuidora->branch_id);
            $this->coordinadorValido($destino, $distribuidora->branch_id);
            $actual = CoordinatorDistributorAssignment::query()->where('distributor_id', $distribuidora->id)->where('status', 'ACTIVE')->whereNull('valid_to')->lockForUpdate()->firstOrFail();
            if ($actual->coordinator_id === $destino->id) {
                throw new ExcepcionCliente('COORDINATOR_ALREADY_ASSIGNED', 'El coordinador destino ya está asignado.', 409);
            }
            $momento = now();
            $actual->update(['status' => 'REASSIGNED', 'valid_to' => $momento, 'ended_by' => $actor->id, 'end_reason' => $motivo, 'lock_version' => $actual->lock_version + 1]);
            $nueva = CoordinatorDistributorAssignment::create(['coordinator_id' => $destino->id, 'distributor_id' => $distribuidora->id, 'branch_id' => $distribuidora->branch_id, 'valid_from' => $momento, 'status' => 'ACTIVE', 'assigned_by' => $actor->id, 'assignment_reason' => $motivo]);
            $this->evento('COORDINATOR_CHANGE', $distribuidora->id, $distribuidora->branch_id, $distribuidora->branch_id, $actor, $motivo, ['coordinator_id' => $actual->coordinator_id, 'assignment_id' => $actual->id], ['coordinator_id' => $destino->id, 'assignment_id' => $nueva->id]);

            return $nueva;
        }, 3);
    }

    public function reasignarSalidaCoordinador(User $coordinadorOrigen, array $destinos, User $actor, string $motivo): array
    {
        return DB::transaction(function () use ($coordinadorOrigen, $destinos, $actor, $motivo): array {
            $activas = CoordinatorDistributorAssignment::query()->where('coordinator_id', $coordinadorOrigen->id)->where('status', 'ACTIVE')->whereNull('valid_to')->lockForUpdate()->get();
            $mapa = collect($destinos)->keyBy('distributor_id');
            if ($activas->isEmpty() || $activas->pluck('distributor_id')->sort()->values()->all() !== $mapa->keys()->sort()->values()->all()) {
                throw new ExcepcionCliente('COORDINATOR_DESTINATIONS_INCOMPLETE', 'Debe indicar un destino válido para cada distribuidora activa del coordinador.', 422);
            }
            $creadas = [];
            foreach ($activas as $activa) {
                $destino = $mapa->get($activa->distributor_id);
                $creadas[] = $this->cambiarCoordinador(Distribuidora::findOrFail($activa->distributor_id), User::findOrFail($destino['destination_coordinator_id']), $actor, $motivo);
            }

            return $creadas;
        }, 3);
    }

    private function bloquear(SolicitudTransferenciaCliente $solicitud, string $estado): SolicitudTransferenciaCliente
    {
        $bloqueada = SolicitudTransferenciaCliente::query()->lockForUpdate()->findOrFail($solicitud->id);
        if ($bloqueada->status !== $estado) {
            throw new ExcepcionCliente('CLIENT_TRANSFER_INVALID_STATE', 'La transición solicitada no corresponde al estado actual.', 409);
        }

        return $bloqueada;
    }

    private function distribuidoraActiva(Distribuidora $distribuidora): void
    {
        if ($distribuidora->status !== EstadoDistribuidora::ACTIVA) {
            throw new ExcepcionCliente('DESTINATION_DISTRIBUTOR_NOT_ACTIVE', 'La distribuidora destino no está activa.', 409);
        }
    }

    private function autorizarGerente(User $actor, string $sucursal): void
    {
        if (! ($actor->hasPermissionTo('organization_changes.manage_global') || ($actor->hasPermissionTo('organization_changes.manage_branch') && $actor->hasScopeForBranch($sucursal)))) {
            throw new ExcepcionCliente('ORGANIZATION_CHANGE_FORBIDDEN', 'No tiene alcance gerencial para el cambio.', 403);
        }
    }

    private function coordinadorValido(User $coordinador, string $sucursal): void
    {
        if (! $coordinador->hasRole('coordinator') || ! $coordinador->hasScopeForBranch($sucursal)) {
            throw new ExcepcionCliente('DESTINATION_COORDINATOR_INVALID', 'El coordinador destino no pertenece a la sucursal.', 422);
        }
    }

    private function evento(string $tipo, string $sujeto, ?string $origen, ?string $destino, User $actor, string $motivo, array $antes, array $despues): void
    {
        EventoCambioOrganizacional::create(['type' => $tipo, 'subject_id' => $sujeto, 'origin_branch_id' => $origen, 'destination_branch_id' => $destino, 'actor_id' => $actor->id, 'reason' => $motivo, 'before_snapshot' => $antes, 'after_snapshot' => $despues, 'occurred_at' => now()]);
        $this->notificar($tipo, $sujeto, ['before' => $antes, 'after' => $despues]);
    }

    private function notificar(string $tipo, string $sujeto, array $payload): void
    {
        OutboxEvent::create(['event_type' => $tipo, 'payload' => ['subject_id' => $sujeto] + $payload, 'status' => 'PENDING']);
    }
}
