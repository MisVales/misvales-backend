<?php

namespace App\Services\Organizacion;

use App\Exceptions\ExcepcionCliente;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\EventoCambioOrganizacional;
use App\Models\OutboxEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class ServicioTransferenciasOrganizacionales
{
    public function cambiarCoordinador(Distribuidora $distribuidora, User $destino, User $actor, string $motivo): CoordinatorDistributorAssignment
    {
        return DB::transaction(function () use ($distribuidora, $destino, $actor, $motivo): CoordinatorDistributorAssignment {
            $distribuidora = Distribuidora::query()->lockForUpdate()->findOrFail($distribuidora->id);
            $this->autorizarGerente($actor, $distribuidora->branch_id);
            $this->coordinadorValido($destino, $distribuidora->branch_id);
            $actual = CoordinatorDistributorAssignment::query()
                ->where('distributor_id', $distribuidora->id)
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->coordinator_id === $destino->id) {
                throw new ExcepcionCliente('COORDINATOR_ALREADY_ASSIGNED', 'El coordinador destino ya está asignado.', 409);
            }

            $momento = $this->momentoPosterior($actual);
            $actual->update([
                'status' => 'REASSIGNED',
                'valid_to' => $momento,
                'ended_by' => $actor->id,
                'end_reason' => $motivo,
                'lock_version' => $actual->lock_version + 1,
            ]);
            $nueva = CoordinatorDistributorAssignment::create([
                'coordinator_id' => $destino->id,
                'distributor_id' => $distribuidora->id,
                'branch_id' => $distribuidora->branch_id,
                'valid_from' => $momento,
                'status' => 'ACTIVE',
                'assigned_by' => $actor->id,
                'assignment_reason' => $motivo,
            ]);
            $antes = ['coordinator_id' => $actual->coordinator_id, 'assignment_id' => $actual->id];
            $despues = ['coordinator_id' => $destino->id, 'assignment_id' => $nueva->id];
            EventoCambioOrganizacional::create([
                'type' => 'COORDINATOR_CHANGE',
                'subject_id' => $distribuidora->id,
                'origin_branch_id' => $distribuidora->branch_id,
                'destination_branch_id' => $distribuidora->branch_id,
                'actor_id' => $actor->id,
                'reason' => $motivo,
                'before_snapshot' => $antes,
                'after_snapshot' => $despues,
                'occurred_at' => now(),
            ]);
            OutboxEvent::create(['event_type' => 'COORDINATOR_CHANGE', 'payload' => ['subject_id' => $distribuidora->id, 'before' => $antes, 'after' => $despues], 'status' => 'PENDING']);

            return $nueva;
        }, 3);
    }

    private function autorizarGerente(User $actor, string $sucursal): void
    {
        if (! ($actor->hasPermissionTo('organization_changes.manage_global')
            || ($actor->hasPermissionTo('organization_changes.manage_branch') && $actor->hasScopeForBranch($sucursal)))) {
            throw new ExcepcionCliente('ORGANIZATION_CHANGE_FORBIDDEN', 'No tiene alcance gerencial para el cambio.', 403);
        }
    }

    private function coordinadorValido(User $coordinador, string $sucursal): void
    {
        if (! $coordinador->hasRole('coordinator') || ! $coordinador->hasScopeForBranch($sucursal)) {
            throw new ExcepcionCliente('DESTINATION_COORDINATOR_INVALID', 'El coordinador destino no pertenece a la sucursal.', 422);
        }
    }

    private function momentoPosterior(CoordinatorDistributorAssignment $actual): CarbonInterface
    {
        $minimo = $actual->valid_from->copy()->addSecond();
        $momento = now();

        return $momento->gt($minimo) ? $momento : $minimo;
    }
}
