<?php

namespace App\Services\Distribuidora;

use App\Enums\EstadoDistribuidora;
use App\Exceptions\ExcepcionDistribuidora;
use App\Models\Distribuidora;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServicioEstadoDistribuidora
{
    public function __construct(
        private readonly ValidadorActivacionDistribuidora $validador,
        private readonly AuditorDistribuidora $auditor,
    ) {}

    public function deshabilitar(Distribuidora $distribuidora, string $motivo, int $version, User $actor): Distribuidora
    {
        return $this->cambiar($distribuidora, EstadoDistribuidora::ACTIVA, EstadoDistribuidora::DESHABILITADA, $motivo, $version, $actor);
    }

    public function habilitar(Distribuidora $distribuidora, string $motivo, int $version, User $actor): Distribuidora
    {
        return DB::transaction(function () use ($distribuidora, $motivo, $version, $actor): Distribuidora {
            $bloqueada = Distribuidora::query()->with(['usuario', 'coordinadorVigente.coordinator', 'categoriaVigente.versionCategoria.category'])->lockForUpdate()->findOrFail($distribuidora->id);
            if ($bloqueada->lock_version !== $version || $bloqueada->status !== EstadoDistribuidora::DESHABILITADA) {
                throw new ExcepcionDistribuidora('DISTRIBUTOR_STATUS_INVALID', 'La distribuidora no puede habilitarse en su estado actual.', 409);
            }
            if ($bloqueada->usuario?->state !== 'ACTIVE' || $bloqueada->coordinadorVigente === null || $bloqueada->categoriaVigente === null) {
                throw new ExcepcionDistribuidora('DISTRIBUTOR_STATUS_INVALID', 'La distribuidora no tiene acceso, coordinador y categoría vigentes.', 409);
            }
            $this->validador->validarCategoria($bloqueada->categoriaVigente->versionCategoria);

            return $this->persistir($bloqueada, EstadoDistribuidora::ACTIVA, $motivo, $actor);
        });
    }

    private function cambiar(Distribuidora $distribuidora, EstadoDistribuidora $origen, EstadoDistribuidora $destino, string $motivo, int $version, User $actor): Distribuidora
    {
        return DB::transaction(function () use ($distribuidora, $origen, $destino, $motivo, $version, $actor): Distribuidora {
            $bloqueada = Distribuidora::query()->lockForUpdate()->findOrFail($distribuidora->id);
            if ($bloqueada->lock_version !== $version || $bloqueada->status !== $origen) {
                throw new ExcepcionDistribuidora('DISTRIBUTOR_STATUS_INVALID', 'La transición de estado no es válida.', 409);
            }

            return $this->persistir($bloqueada, $destino, $motivo, $actor);
        });
    }

    private function persistir(Distribuidora $distribuidora, EstadoDistribuidora $destino, string $motivo, User $actor): Distribuidora
    {
        $anterior = $distribuidora->status;
        $distribuidora->forceFill([
            'status' => $destino,
            'lock_version' => $distribuidora->lock_version + 1,
        ])->save();
        $payload = ['distributor_id' => $distribuidora->id, 'from_status' => $anterior->value, 'to_status' => $destino->value];
        OutboxEvent::query()->create(['event_type' => 'DISTRIBUTOR_STATUS_CHANGED', 'payload' => $payload, 'status' => 'PENDING']);
        $this->auditor->registrar(
            'DISTRIBUTOR_STATUS_CHANGED',
            'Distributor',
            $distribuidora->id,
            $actor,
            $distribuidora->branch_id,
            ['status' => $anterior->value],
            ['status' => $destino->value],
            $motivo,
        );

        return $distribuidora->refresh();
    }
}
