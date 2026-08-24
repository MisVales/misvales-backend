<?php

namespace App\Services\Distribuidora;

use App\Exceptions\ExcepcionDistribuidora;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\CategoryVersion;
use App\Models\Distribuidora;
use App\Models\OutboxEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ServicioAsignacionCategoria
{
    public function __construct(
        private readonly ValidadorActivacionDistribuidora $validador,
        private readonly AuditorDistribuidora $auditor,
    ) {}

    public function asignar(Distribuidora $distribuidora, array $datos, User $actor): AsignacionCategoriaDistribuidora
    {
        return DB::transaction(function () use ($distribuidora, $datos, $actor): AsignacionCategoriaDistribuidora {
            $bloqueada = Distribuidora::query()->lockForUpdate()->findOrFail($distribuidora->id);
            if ($bloqueada->lock_version !== (int) $datos['lock_version']) {
                throw new ExcepcionDistribuidora('RESOURCE_VERSION_CONFLICT', 'La distribuidora fue modificada por otra operación.', 409);
            }

            $fecha = isset($datos['starts_at'])
                ? CarbonImmutable::parse($datos['starts_at'])
                : CarbonImmutable::now();
            $version = CategoryVersion::query()->with('category')->lockForUpdate()->findOrFail($datos['category_version_id']);

            $anterior = AsignacionCategoriaDistribuidora::query()
                ->where('distributor_id', $bloqueada->id)
                ->whereNull('ends_at')
                ->lockForUpdate()
                ->first();

            // Estas columnas se almacenan con precisión de segundos. Evita que dos
            // asignaciones inmediatas terminen con el mismo instante persistido.
            if ($anterior !== null && $fecha->diffInSeconds($anterior->starts_at) < 1) {
                $fecha = $anterior->starts_at->addSecond();
            }

            $this->validador->validarCategoriaEnFecha($version, $fecha);

            if ($anterior !== null) {
                $anterior->update(['ends_at' => $fecha]);
            }

            $asignacion = AsignacionCategoriaDistribuidora::create([
                'distributor_id' => $bloqueada->id,
                'category_version_id' => $version->id,
                'starts_at' => $fecha,
                'assigned_by' => $actor->id,
                'reason' => $datos['reason'] ?? null,
            ]);
            Distribuidora::query()
                ->whereKey($bloqueada->id)
                ->where('lock_version', $bloqueada->lock_version)
                ->increment('lock_version');

            OutboxEvent::create([
                'event_type' => 'DISTRIBUTOR_CATEGORY_ASSIGNED',
                'payload' => [
                    'event_code' => 'EV-093',
                    'distributor_id' => $bloqueada->id,
                    'previous_category_version_id' => $anterior?->category_version_id,
                    'category_version_id' => $version->id,
                    'starts_at' => $fecha->toIso8601String(),
                ],
                'status' => 'PENDING',
            ]);

            $this->auditor->registrar(
                'DISTRIBUTOR_CATEGORY_ASSIGNED',
                'Distributor',
                $bloqueada->id,
                $actor,
                $bloqueada->branch_id,
                ['category_version_id' => $anterior?->category_version_id],
                ['category_version_id' => $version->id, 'starts_at' => $fecha->toIso8601String()],
                $datos['reason'] ?? null,
            );

            return $asignacion->load('versionCategoria.category', 'asignadaPor');
        });
    }
}
