<?php

namespace App\Services;

use App\Enums\RedemptionPeriodStatus;
use App\Exceptions\BusinessException;
use App\Models\RedemptionPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PeriodoCanjeServicio
{
    public function crearPeriodo(array $datos, string $usuarioId): RedemptionPeriod
    {
        $startsAt = Carbon::parse($datos['starts_at'], 'America/Monterrey')->setTimezone('UTC');
        $endsAt = Carbon::parse($datos['ends_at'], 'America/Monterrey')->setTimezone('UTC');
        $configuracionPunto = app(ConfiguracionServicio::class)->resolver('POINT_VALUE_AMOUNT');

        return RedemptionPeriod::create([
            'code' => $datos['code'],
            'name' => $datos['name'],
            'description' => $datos['description'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => RedemptionPeriodStatus::DRAFT,
            'point_value' => bcadd((string) $configuracionPunto['value'], '0', 4),
            'point_value_configuration_version_id' => $configuracionPunto['version_id'],
            'reason' => $datos['reason'],
            'created_by' => $usuarioId,
        ]);
    }

    public function actualizarPeriodo(RedemptionPeriod $periodo, array $datos): RedemptionPeriod
    {
        if ($periodo->status !== RedemptionPeriodStatus::DRAFT) {
            throw new BusinessException('VERSION_NOT_EDITABLE', 'Solo los periodos en estado DRAFT pueden ser modificados.');
        }

        // Punto 72: Impedir modificar un periodo de canje después de iniciado.
        if ($periodo->starts_at->isPast()) {
            throw new BusinessException('VERSION_NOT_EDITABLE', 'No se puede modificar un periodo de canje que ya ha iniciado o pasado.');
        }

        if (array_key_exists('lock_version', $datos)) {
            if ((int) $periodo->lock_version !== (int) $datos['lock_version']) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'La versión del periodo fue modificada por otro usuario.');
            }
            $periodo->lock_version++;
        }

        if (array_key_exists('name', $datos)) {
            $periodo->name = $datos['name'];
        }
        if (array_key_exists('description', $datos)) {
            $periodo->description = $datos['description'];
        }
        if (array_key_exists('reason', $datos)) {
            $periodo->reason = $datos['reason'];
        }

        if (array_key_exists('starts_at', $datos)) {
            $periodo->starts_at = Carbon::parse($datos['starts_at'], 'America/Monterrey')->setTimezone('UTC');
        }
        if (array_key_exists('ends_at', $datos)) {
            $periodo->ends_at = Carbon::parse($datos['ends_at'], 'America/Monterrey')->setTimezone('UTC');
        }

        $periodo->save();

        return $periodo;
    }

    public function publicarPeriodo(RedemptionPeriod $periodo, array $datos, string $usuarioId): RedemptionPeriod
    {
        if ($periodo->status !== RedemptionPeriodStatus::DRAFT) {
            throw new BusinessException('VERSION_ALREADY_PUBLISHED', 'Solo los periodos en DRAFT pueden ser publicados.');
        }

        if ($periodo->starts_at->isPast()) {
            throw new BusinessException('INVALID_VALIDITY', 'No se puede publicar un periodo cuya fecha de inicio ya ha pasado.');
        }

        if ($periodo->point_value === null || $periodo->point_value_configuration_version_id === null) {
            throw new BusinessException('REDEMPTION_POINT_VALUE_CONFIGURATION_REQUIRED', 'El periodo requiere valor de punto y versión de configuración trazable.');
        }

        return DB::transaction(function () use ($periodo, $datos, $usuarioId) {
            if (array_key_exists('lock_version', $datos)) {
                if ((int) $periodo->lock_version !== (int) $datos['lock_version']) {
                    throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'La versión del periodo fue modificada por otro usuario.');
                }
                $periodo->lock_version++;
            }

            // Punto 70: Impedir periodos de canje publicados con traslapes.
            $traslape = RedemptionPeriod::whereIn('status', [
                RedemptionPeriodStatus::SCHEDULED,
                RedemptionPeriodStatus::OPEN,
            ])
                ->where('id', '!=', $periodo->id)
                ->where(function ($q) use ($periodo) {
                    $q->where('starts_at', '<', $periodo->ends_at)
                        ->where('ends_at', '>', $periodo->starts_at);
                })
                ->lockForUpdate() // Concurrencia
                ->exists();

            if ($traslape) {
                throw new BusinessException('REDEMPTION_PERIOD_OVERLAP', 'El periodo de canje se traslapa con un periodo ya publicado. Debe ajustar las fechas.');
            }

            $periodo->status = $periodo->starts_at->isFuture()
                ? RedemptionPeriodStatus::SCHEDULED
                : RedemptionPeriodStatus::OPEN;
            $periodo->reason .= "\n[Publicación]: ".$datos['reason'];
            $periodo->published_by = $usuarioId;
            $periodo->published_at = now();
            $periodo->save();

            Cache::forget('periodo_canje:vigente');

            return $periodo;
        });
    }

    public function cancelarPeriodo(RedemptionPeriod $periodo, array $datos, string $usuarioId): RedemptionPeriod
    {
        // Punto 73: Permitir cancelar un periodo futuro mediante una transición explícita
        if ($periodo->status === RedemptionPeriodStatus::CANCELLED || $periodo->status === RedemptionPeriodStatus::CLOSED) {
            throw new BusinessException('VERSION_NOT_EDITABLE', 'El periodo ya está cerrado o cancelado.');
        }

        if ($periodo->starts_at->isPast()) {
            throw new BusinessException('INVALID_VALIDITY', 'No se puede cancelar un periodo que ya ha iniciado. Solo aplica a periodos futuros.');
        }

        if (array_key_exists('lock_version', $datos)) {
            if ((int) $periodo->lock_version !== (int) $datos['lock_version']) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'La versión del periodo fue modificada por otro usuario.');
            }
            $periodo->lock_version++;
        }
        $periodo->status = RedemptionPeriodStatus::CANCELLED;
        $periodo->reason .= "\n[Cancelación]: ".$datos['reason'];
        $periodo->closed_by = $usuarioId;
        $periodo->closed_at = now();
        $periodo->save();

        Cache::forget('periodo_canje:vigente');

        return $periodo;
    }

    public function resolverVigente(?Carbon $fecha = null): ?array
    {
        $fechaConsulta = $fecha ?? now();
        $isCurrent = ! $fecha || $fechaConsulta->diffInSeconds(now()) < 5;

        if ($isCurrent) {
            $this->sincronizarEstadosTemporales($fechaConsulta);
        }

        if ($isCurrent) {
            return Cache::remember('periodo_canje:vigente', $this->calcularCacheTTL(), fn () => $this->resolverDesdeBD($fechaConsulta));
        }

        return $this->resolverDesdeBD($fechaConsulta);
    }

    private function resolverDesdeBD(Carbon $fecha): ?array
    {
        // Punto 71: Mantener cerrado el canje cuando no exista un periodo publicado y vigente.
        // Aquí si no existe, regresamos null y los clientes de este servicio sabrán que el canje está inactivo.
        $periodo = RedemptionPeriod::whereIn('status', [
            RedemptionPeriodStatus::SCHEDULED,
            RedemptionPeriodStatus::OPEN,
        ])
            ->where('starts_at', '<=', $fecha)
            ->where('ends_at', '>', $fecha)
            ->first();

        if (! $periodo) {
            return null;
        }

        return [
            'id' => $periodo->id,
            'code' => $periodo->code,
            'name' => $periodo->name,
            'description' => $periodo->description,
            'starts_at' => $periodo->starts_at->toIso8601String(),
            'ends_at' => $periodo->ends_at->toIso8601String(),
            'point_value' => $periodo->point_value,
        ];
    }

    private function calcularCacheTTL(): Carbon
    {
        $proximoEvento = RedemptionPeriod::whereIn('status', [
            RedemptionPeriodStatus::SCHEDULED,
            RedemptionPeriodStatus::OPEN,
        ])
            ->where(function ($q) {
                $q->where('starts_at', '>', now())
                    ->orWhere('ends_at', '>', now());
            })
            ->orderByRaw('CASE WHEN starts_at > NOW() THEN starts_at ELSE ends_at END ASC')
            ->first();

        if ($proximoEvento) {
            return $proximoEvento->starts_at->isFuture() ? $proximoEvento->starts_at : $proximoEvento->ends_at;
        }

        return now()->addHours(24);
    }

    private function sincronizarEstadosTemporales(Carbon $fecha): void
    {
        DB::transaction(function () use ($fecha) {
            RedemptionPeriod::where('status', RedemptionPeriodStatus::OPEN)
                ->where('ends_at', '<=', $fecha)
                ->update([
                    'status' => RedemptionPeriodStatus::CLOSED->value,
                    'closed_at' => $fecha,
                    'updated_at' => $fecha,
                ]);

            RedemptionPeriod::where('status', RedemptionPeriodStatus::SCHEDULED)
                ->where('starts_at', '<=', $fecha)
                ->where('ends_at', '>', $fecha)
                ->update([
                    'status' => RedemptionPeriodStatus::OPEN->value,
                    'updated_at' => $fecha,
                ]);
        });
    }
}
