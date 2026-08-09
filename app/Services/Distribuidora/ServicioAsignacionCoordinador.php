<?php

namespace App\Services\Distribuidora;

use App\Enums\EstadoDistribuidora;
use App\Exceptions\ExcepcionDistribuidora;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServicioAsignacionCoordinador
{
    public function __construct(private readonly AuditorDistribuidora $auditor) {}

    public function asignar(Distribuidora $distribuidora, string $coordinadorId, string $motivo, int $version, User $actor): CoordinatorDistributorAssignment
    {
        return DB::transaction(function () use ($distribuidora, $coordinadorId, $motivo, $version, $actor): CoordinatorDistributorAssignment {
            $bloqueada = Distribuidora::query()->lockForUpdate()->findOrFail($distribuidora->id);
            if ($bloqueada->lock_version !== $version) {
                throw new ExcepcionDistribuidora('RESOURCE_VERSION_CONFLICT', 'La distribuidora fue modificada por otra operación.', 409);
            }
            if ($bloqueada->status === EstadoDistribuidora::DESHABILITADA) {
                throw new ExcepcionDistribuidora('DISTRIBUTOR_STATUS_INVALID', 'No se puede reasignar una distribuidora deshabilitada.', 409);
            }

            $coordinador = User::query()->lockForUpdate()->find($coordinadorId);
            $valido = $coordinador?->state === 'ACTIVE'
                && $coordinador->roleScopes()
                    ->where('status', 'ACTIVE')
                    ->whereNull('revoked_at')
                    ->where('scope_type', 'BRANCH')
                    ->where('branch_id', $bloqueada->branch_id)
                    ->whereHas('role', fn ($query) => $query->where('code', 'coordinator'))
                    ->exists();
            if (! $valido) {
                throw new ExcepcionDistribuidora(
                    'DISTRIBUTOR_COORDINATOR_SCOPE_INVALID',
                    'El coordinador no está activo o no pertenece a la sucursal.',
                    422,
                );
            }

            $actual = CoordinatorDistributorAssignment::query()
                ->where('distributor_id', $bloqueada->id)
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->lockForUpdate()
                ->first();
            if ($actual?->coordinator_id === $coordinador->id) {
                return $actual->load('coordinator', 'assignedBy');
            }

            $fecha = now();
            if ($actual !== null) {
                $fecha = $actual->valid_from->copy()->addSecond();
                $actual->update([
                    'status' => 'REASSIGNED',
                    'valid_to' => $fecha,
                    'ended_by' => $actor->id,
                    'end_reason' => $motivo,
                    'lock_version' => $actual->lock_version + 1,
                ]);
            }

            $asignacion = CoordinatorDistributorAssignment::query()->create([
                'coordinator_id' => $coordinador->id,
                'distributor_id' => $bloqueada->id,
                'branch_id' => $bloqueada->branch_id,
                'valid_from' => $fecha,
                'status' => 'ACTIVE',
                'assigned_by' => $actor->id,
                'assignment_reason' => $motivo,
            ]);
            $bloqueada->forceFill(['lock_version' => $bloqueada->lock_version + 1])->save();

            $payload = [
                'distributor_id' => $bloqueada->id,
                'previous_coordinator_id' => $actual?->coordinator_id,
                'coordinator_id' => $coordinador->id,
                'branch_id' => $bloqueada->branch_id,
            ];
            OutboxEvent::query()->create(['event_type' => 'DISTRIBUTOR_COORDINATOR_ASSIGNED', 'payload' => $payload, 'status' => 'PENDING']);
            $this->auditor->registrar(
                'DISTRIBUTOR_COORDINATOR_ASSIGNED',
                'Distributor',
                $bloqueada->id,
                $actor,
                $bloqueada->branch_id,
                ['coordinator_id' => $actual?->coordinator_id],
                ['coordinator_id' => $coordinador->id],
                $motivo,
            );

            return $asignacion->load('coordinator', 'assignedBy');
        });
    }
}
