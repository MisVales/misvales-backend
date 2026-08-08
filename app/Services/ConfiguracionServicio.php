<?php

namespace App\Services;

use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ConfiguracionServicio
{
    public function crearConfiguracion(array $datos, string $usuarioId): ConfigurationDefinition
    {
        return DB::transaction(function () use ($datos, $usuarioId) {
            $configuracion = ConfigurationDefinition::create([
                'key' => $datos['key'],
                'name' => $datos['name'],
                'description' => $datos['description'] ?? null,
                'value_type' => $datos['value_type'],
                'unit' => $datos['unit'] ?? null,
                'is_required' => $datos['is_required'] ?? true,
                'is_sensitive' => $datos['is_sensitive'] ?? false,
                'status' => BaseStatus::ACTIVE,
                'created_by' => $usuarioId,
            ]);

            $this->crearVersion($configuracion, [
                'value' => $datos['value'],
                'reason' => $datos['reason'],
                'effective_from' => $datos['effective_from'],
            ], $usuarioId);

            return $configuracion->load('versions');
        });
    }

    private function normalizarValor(mixed $valor, string $tipo): mixed
    {
        $enumTipo = \App\Enums\ConfigurationValueType::tryFrom($tipo);
        return match ($enumTipo) {
            \App\Enums\ConfigurationValueType::DECIMAL,
            \App\Enums\ConfigurationValueType::PERCENTAGE => (float) number_format((float) $valor, 4, '.', ''),
            \App\Enums\ConfigurationValueType::INTEGER => (int) $valor,
            default => $valor,
        };
    }

    public function crearVersion(ConfigurationDefinition $configuracion, array $datos, string $usuarioId): ConfigurationVersion
    {
        $ultimaVersion = $configuracion->versions()->max('version') ?? 0;
        
        // Interpretar effective_from en Monterrey y guardar en UTC (Puntos 17 y 18)
        $effectiveFrom = \Carbon\Carbon::parse($datos['effective_from'], 'America/Monterrey')->setTimezone('UTC');

        return ConfigurationVersion::create([
            'configuration_definition_id' => $configuracion->id,
            'version' => $ultimaVersion + 1,
            'value' => $this->normalizarValor($datos['value'], $configuracion->value_type),
            'status' => VersionStatus::DRAFT,
            'effective_from' => $effectiveFrom,
            'reason' => $datos['reason'],
            'created_by' => $usuarioId,
        ]);
    }

    public function actualizarVersion(ConfigurationVersion $version, array $datos): ConfigurationVersion
    {
        // Punto 32: Impedir modificar directamente una versión publicada.
        if ($version->status !== VersionStatus::DRAFT) {
            throw new \App\Exceptions\BusinessException('CONFIGURATION_VERSION_IMMUTABLE', 'Solo las versiones en estado DRAFT pueden ser modificadas directamente.');
        }

        if (array_key_exists('value', $datos)) {
            $version->value = $this->normalizarValor($datos['value'], $version->definition->value_type);
        }
        if (isset($datos['reason'])) {
            $version->reason = $datos['reason'];
        }
        if (isset($datos['effective_from'])) {
            $version->effective_from = \Carbon\Carbon::parse($datos['effective_from'], 'America/Monterrey')->setTimezone('UTC');
        }

        $version->save();
        return $version;
    }

    public function desactivarVersion(ConfigurationVersion $version, string $usuarioId): ConfigurationVersion
    {
        $version->status = VersionStatus::INACTIVE;
        
        // Si estaba publicada y ya había iniciado, cerramos su vigencia en este momento exacto
        if (!$version->effective_to && $version->effective_from && $version->effective_from->isPast()) {
            $version->effective_to = now();
        }

        $version->save();

        // Punto 42: Invalidar la caché
        \Illuminate\Support\Facades\Cache::forget('configuraciones:todas_vigentes');
        \Illuminate\Support\Facades\Cache::forget("configuracion:{$version->definition->key}");

        return $version;
    }

    public function publicarVersion(ConfigurationVersion $version, string $usuarioId): ConfigurationVersion
    {
        $statusValue = $version->status instanceof VersionStatus ? $version->status->value : $version->status;
        
        if ($statusValue !== VersionStatus::DRAFT->value) {
            throw new \App\Exceptions\BusinessException('CONFIGURATION_VERSION_IMMUTABLE', 'Solo las versiones en DRAFT pueden ser publicadas.');
        }

        return DB::transaction(function () use ($version, $usuarioId) {
            $now = now();

            // Encontrar la versión actualmente publicada y sin cerrar
            $versionPrevia = ConfigurationVersion::where('configuration_definition_id', $version->configuration_definition_id)
                ->where('status', VersionStatus::PUBLISHED)
                ->whereNull('effective_to')
                ->first();

            if ($versionPrevia) {
                // Validar que no haya traslapes ilógicos (Punto 29 y 30)
                if ($version->effective_from->lessThanOrEqualTo($versionPrevia->effective_from)) {
                    throw new \App\Exceptions\BusinessException('CONFIGURATION_VALIDITY_OVERLAP', 'La vigencia debe ser estrictamente posterior a la versión publicada actual.');
                }

                // Punto 35: Impedir cambios retroactivos en la capa de negocio
                if ($version->effective_from->isPast()) {
                    throw new \App\Exceptions\BusinessException('INVALID_VALIDITY', 'No se pueden programar versiones con fechas retroactivas.');
                }

                // La versión previa se cierra en el momento que inicia la nueva (Punto 34)
                $versionPrevia->effective_to = $version->effective_from;
                
                // Si la nueva versión aplica inmediatamente, la previa pasa a INACTIVE.
                // Si la nueva versión es a futuro, la previa se queda PUBLISHED hasta que llegue la fecha (Punto 31)
                if ($version->effective_from->isPast() || $version->effective_from->isCurrentMinute()) {
                    $versionPrevia->status = VersionStatus::INACTIVE;
                }
                
                $versionPrevia->save();
            }

            $version->status = VersionStatus::PUBLISHED;
            $version->published_by = $usuarioId;
            $version->published_at = $now;
            $version->save();
            
            // Punto 87: Publicar evento en Outbox (misma transacción)
            \App\Services\OutboxService::publish('ConfigurationPublished', [
                'configuration_key' => $version->definition->key,
                'version_id' => $version->id,
                'published_by' => $usuarioId,
            ]);

            // Punto 42: Invalidar caché manualmente cuando se publica o desactiva algo
            \Illuminate\Support\Facades\Cache::forget('configuraciones:todas_vigentes');
            \Illuminate\Support\Facades\Cache::forget("configuracion:{$version->definition->key}");

            return $version;
        });
    }

    /**
     * Resuelve el valor de una configuración en un momento específico (Punto 36 y 38)
     */
    public function resolver(string $key, ?\Carbon\Carbon $fecha = null): array
    {
        $fechaConsulta = $fecha ?? now();
        $isCurrent = !$fecha || $fechaConsulta->diffInSeconds(now()) < 5;

        // Punto 41: Caché solo para vigentes actuales
        if ($isCurrent) {
            return \Illuminate\Support\Facades\Cache::remember(
                "configuracion:{$key}", 
                $this->calcularCacheTTL(), 
                fn () => $this->resolverDesdeBD($key, $fechaConsulta)
            );
        }

        return $this->resolverDesdeBD($key, $fechaConsulta);
    }

    private function resolverDesdeBD(string $key, \Carbon\Carbon $fecha): array
    {
        $version = ConfigurationVersion::whereHas('definition', function ($q) use ($key) {
                $q->where('key', $key)->where('status', BaseStatus::ACTIVE);
            })
            ->where('status', VersionStatus::PUBLISHED)
            ->where('effective_from', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>', $fecha);
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if (!$version) {
            throw new \App\Exceptions\BusinessException('CONFIGURATION_NOT_FOUND', "No se encontró una versión publicada y vigente para la configuración: {$key}");
        }

        return [
            'definition_id' => $version->definition->id,
            'version_id' => $version->id,
            'key' => $version->definition->key,
            'value' => $version->value,
            'type' => $version->definition->value_type,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'effective_to' => $version->effective_to?->toIso8601String(),
            'version' => $version->version,
        ];
    }

    /**
     * Obtiene todas las configuraciones vigentes en un momento dado (Punto 37 y 38)
     */
    public function obtenerVigentes(?\Carbon\Carbon $fecha = null): array
    {
        $fechaConsulta = $fecha ?? now();
        $isCurrent = !$fecha || $fechaConsulta->diffInSeconds(now()) < 5;

        if ($isCurrent) {
            return \Illuminate\Support\Facades\Cache::remember(
                'configuraciones:todas_vigentes', 
                $this->calcularCacheTTL(), 
                fn () => $this->obtenerVigentesDesdeBD($fechaConsulta)
            );
        }

        return $this->obtenerVigentesDesdeBD($fechaConsulta);
    }

    private function obtenerVigentesDesdeBD(\Carbon\Carbon $fecha): array
    {
        $versiones = ConfigurationVersion::with('definition')
            ->whereHas('definition', function ($q) {
                $q->where('status', BaseStatus::ACTIVE);
            })
            ->where('status', VersionStatus::PUBLISHED)
            ->where('effective_from', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>', $fecha);
            })
            ->orderBy('effective_from', 'desc')
            ->get()
            ->unique('configuration_definition_id');

        $resultado = [];
        foreach ($versiones as $version) {
            $resultado[$version->definition->key] = [
                'definition_id' => $version->definition->id,
                'version_id' => $version->id,
                'key' => $version->definition->key,
                'value' => $version->value,
                'type' => $version->definition->value_type,
                'effective_from' => $version->effective_from?->toIso8601String(),
                'effective_to' => $version->effective_to?->toIso8601String(),
                'version' => $version->version,
            ];
        }

        return $resultado;
    }

    /**
     * Calcula el tiempo de vida de la caché (TTL) garantizando la invalidación automática 
     * cuando una versión programada a futuro deba entrar en vigor (Punto 42).
     */
    private function calcularCacheTTL(): \Carbon\Carbon
    {
        $proximaVersion = ConfigurationVersion::where('status', VersionStatus::PUBLISHED)
            ->where('effective_from', '>', now())
            ->orderBy('effective_from', 'asc')
            ->first();

        // Si hay una versión futura, la caché caduca exactamente en ese milisegundo.
        // Si no, caduca en 24 horas por seguridad.
        return $proximaVersion ? $proximaVersion->effective_from : now()->addHours(24);
    }
}
