<?php

namespace App\Services;

use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\CategoryVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CategoriaServicio
{
    public function crearCategoria(array $datos, string $usuarioId): Category
    {
        return DB::transaction(function () use ($datos, $usuarioId) {
            $categoria = Category::create([
                'code' => $datos['code'],
                'status' => BaseStatus::ACTIVE,
                'created_by' => $usuarioId,
            ]);

            $this->crearVersion($categoria, $datos, $usuarioId);

            return $categoria->load('versions');
        });
    }

    public function crearVersion(Category $categoria, array $datos, string $usuarioId): CategoryVersion
    {
        $ultimaVersion = $categoria->versions()->max('version') ?? 0;

        $effectiveFrom = Carbon::parse($datos['effective_from'], 'America/Monterrey')->setTimezone('UTC');

        return CategoryVersion::create([
            'category_id' => $categoria->id,
            'version' => $ultimaVersion + 1,
            'name' => $datos['name'],
            'description' => $datos['description'] ?? null,
            'profit_percentage' => $this->normalizarPorcentaje($datos['profit_percentage']),
            'status' => VersionStatus::DRAFT,
            'effective_from' => $effectiveFrom,
            'reason' => $datos['reason'],
            'created_by' => $usuarioId,
        ]);
    }

    private function normalizarPorcentaje(mixed $valor): string
    {
        return number_format((float) $valor, 4, '.', '');
    }

    public function actualizarVersion(CategoryVersion $version, array $datos): CategoryVersion
    {
        // Punto 53: Impedir editar una versión de categoría publicada.
        if ($version->status !== VersionStatus::DRAFT) {
            throw new BusinessException('CATEGORY_VERSION_IMMUTABLE', 'Solo las versiones en estado DRAFT pueden ser modificadas directamente.');
        }

        if (array_key_exists('lock_version', $datos)) {
            if ($version->lock_version !== (int) $datos['lock_version']) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'La versión de la categoría fue modificada por otro usuario.');
            }
            $version->lock_version++;
        }

        if (array_key_exists('name', $datos)) {
            $version->name = $datos['name'];
        }
        if (array_key_exists('description', $datos)) {
            $version->description = $datos['description'];
        }
        if (array_key_exists('profit_percentage', $datos)) {
            $version->profit_percentage = $this->normalizarPorcentaje($datos['profit_percentage']);
        }
        if (array_key_exists('reason', $datos)) {
            $version->reason = $datos['reason'];
        }
        if (array_key_exists('effective_from', $datos)) {
            $version->effective_from = Carbon::parse($datos['effective_from'], 'America/Monterrey')->setTimezone('UTC');
        }

        $version->save();

        return $version;
    }

    public function publicarVersion(CategoryVersion $version, array $datos, string $usuarioId): CategoryVersion
    {
        if ($version->status !== VersionStatus::DRAFT) {
            throw new BusinessException('CATEGORY_VERSION_IMMUTABLE', 'Solo las versiones en DRAFT pueden ser publicadas.');
        }

        if (array_key_exists('lock_version', $datos)) {
            if ($version->lock_version !== (int) $datos['lock_version']) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'La versión de la categoría fue modificada por otro usuario.');
            }
            $version->lock_version++;
        }

        return DB::transaction(function () use ($version, $datos, $usuarioId) {
            $versionPrevia = CategoryVersion::where('category_id', $version->category_id)
                ->where('status', VersionStatus::PUBLISHED)
                ->whereNull('effective_to')
                ->lockForUpdate() // Evitar vigencias concurrentes
                ->first();

            if ($versionPrevia) {
                if ($version->effective_from->lessThanOrEqualTo($versionPrevia->effective_from)) {
                    throw new BusinessException('OVERLAPPING_VALIDITY', 'La vigencia debe ser estrictamente posterior a la versión publicada actual.');
                }

                if ($version->effective_from->isPast()) {
                    throw new BusinessException('INVALID_VALIDITY', 'No se pueden programar versiones con fechas retroactivas.');
                }

                $versionPrevia->effective_to = $version->effective_from;

                if ($version->effective_from->isPast() || $version->effective_from->isCurrentMinute()) {
                    $versionPrevia->status = VersionStatus::INACTIVE;
                }

                $versionPrevia->save();
            }

            $version->status = VersionStatus::PUBLISHED;
            $version->reason .= "\n[Publicación]: ".$datos['reason'];
            $version->published_by = $usuarioId;
            $version->published_at = now();
            $version->save();

            Cache::forget('categorias:todas_vigentes');
            Cache::forget("categoria:{$version->category->code}");

            return $version;
        });
    }

    public function desactivarCategoria(Category $categoria, string $usuarioId): Category
    {
        // Punto 54: Permitir desactivar una categoría utilizada sin eliminar sus versiones
        $categoria->status = BaseStatus::INACTIVE;
        $categoria->updated_by = $usuarioId;
        $categoria->save();

        Cache::forget('categorias:todas_vigentes');
        Cache::forget("categoria:{$categoria->code}");

        return $categoria;
    }

    public function resolver(string $code, ?Carbon $fecha = null): array
    {
        $fechaConsulta = $fecha ?? now();
        $isCurrent = ! $fecha || $fechaConsulta->diffInSeconds(now()) < 5;

        if ($isCurrent) {
            return Cache::remember("categoria:{$code}", $this->calcularCacheTTL(), fn () => $this->resolverDesdeBD($code, $fechaConsulta));
        }

        return $this->resolverDesdeBD($code, $fechaConsulta);
    }

    private function resolverDesdeBD(string $code, Carbon $fecha): array
    {
        // Punto 55: Impedir utilizar una categoría sin versión publicada y vigente
        $version = CategoryVersion::whereHas('category', function ($q) use ($code) {
            $q->where('code', $code)->where('status', BaseStatus::ACTIVE);
        })
            ->where('status', VersionStatus::PUBLISHED)
            ->where('effective_from', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $fecha);
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if (! $version) {
            throw new BusinessException('CATEGORY_VERSION_NOT_PUBLISHED', "No existe una versión publicada y vigente para la categoría activa: {$code} (Punto 55)");
        }

        return [
            'category_id' => $version->category_id,
            'version_id' => $version->id,
            'code' => $version->category->code,
            'name' => $version->name,
            'description' => $version->description,
            'profit_percentage' => $version->profit_percentage,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'effective_to' => $version->effective_to?->toIso8601String(),
            'version' => $version->version,
        ];
    }

    private function calcularCacheTTL(): Carbon
    {
        $proximaVersion = CategoryVersion::where('status', VersionStatus::PUBLISHED)
            ->where('effective_from', '>', now())
            ->orderBy('effective_from', 'asc')
            ->first();

        return $proximaVersion ? $proximaVersion->effective_from : now()->addHours(24);
    }
}
