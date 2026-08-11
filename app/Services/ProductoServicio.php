<?php

namespace App\Services;

use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductoServicio
{
    public function crearProducto(array $datos, string $usuarioId): Product
    {
        return DB::transaction(function () use ($datos, $usuarioId) {
            $producto = Product::create([
                'code' => $datos['code'],
                'status' => BaseStatus::ACTIVE,
                'created_by' => $usuarioId,
            ]);

            $this->crearVersion($producto, $datos, $usuarioId);

            return $producto->load('versions');
        });
    }

    public function crearVersion(Product $producto, array $datos, string $usuarioId): ProductVersion
    {
        $ultimaVersion = $producto->versions()->max('version') ?? 0;

        $effectiveFrom = Carbon::parse($datos['effective_from'], 'America/Monterrey')->setTimezone('UTC');

        return ProductVersion::create([
            'product_id' => $producto->id,
            'version' => $ultimaVersion + 1,
            'name' => $datos['name'],
            'description' => $datos['description'] ?? null,
            'nominal_amount' => $this->normalizarMonto($datos['nominal_amount']),
            'loan_commission_percentage' => $this->normalizarPorcentaje($datos['loan_commission_percentage']),
            'simple_interest_percentage' => $this->normalizarPorcentaje($datos['simple_interest_percentage']),
            'insurance_amount' => $this->normalizarMonto($datos['insurance_amount']),
            'fortnights_count' => (int) $datos['fortnights_count'],
            'status' => VersionStatus::DRAFT,
            'effective_from' => $effectiveFrom,
            'reason' => $datos['reason'],
            'created_by' => $usuarioId,
        ]);
    }

    private function normalizarPorcentaje(mixed $valor): string
    {
        return bcadd((string) $valor, '0', 6);
    }

    private function normalizarMonto(mixed $valor): string
    {
        return bcadd((string) $valor, '0', 4);
    }

    public function actualizarVersion(ProductVersion $version, array $datos): ProductVersion
    {
        // Punto 64: Impedir editar una versión de producto publicada.
        if ($version->status !== VersionStatus::DRAFT) {
            throw new BusinessException('PRODUCT_VERSION_IMMUTABLE', 'Solo las versiones en estado DRAFT pueden ser modificadas directamente.');
        }

        if (array_key_exists('lock_version', $datos)) {
            if ($version->lock_version !== (int) $datos['lock_version']) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'La versión del producto fue modificada por otro usuario.');
            }
            $version->lock_version++;
        }

        if (array_key_exists('name', $datos)) {
            $version->name = $datos['name'];
        }
        if (array_key_exists('description', $datos)) {
            $version->description = $datos['description'];
        }

        if (array_key_exists('nominal_amount', $datos)) {
            $version->nominal_amount = $this->normalizarMonto($datos['nominal_amount']);
        }
        if (array_key_exists('loan_commission_percentage', $datos)) {
            $version->loan_commission_percentage = $this->normalizarPorcentaje($datos['loan_commission_percentage']);
        }
        if (array_key_exists('simple_interest_percentage', $datos)) {
            $version->simple_interest_percentage = $this->normalizarPorcentaje($datos['simple_interest_percentage']);
        }
        if (array_key_exists('insurance_amount', $datos)) {
            $version->insurance_amount = $this->normalizarMonto($datos['insurance_amount']);
        }
        if (array_key_exists('fortnights_count', $datos)) {
            $version->fortnights_count = (int) $datos['fortnights_count'];
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

    public function publicarVersion(ProductVersion $version, array $datos, string $usuarioId): ProductVersion
    {
        if ($version->status !== VersionStatus::DRAFT) {
            throw new BusinessException('PRODUCT_VERSION_IMMUTABLE', 'Solo las versiones en DRAFT pueden ser publicadas.');
        }

        // Punto 63: Impedir publicar un producto incompleto
        if (
            is_null($version->name) ||
            is_null($version->nominal_amount) ||
            is_null($version->loan_commission_percentage) ||
            is_null($version->simple_interest_percentage) ||
            is_null($version->insurance_amount) ||
            is_null($version->fortnights_count)
        ) {
            throw new BusinessException('PRODUCT_INCOMPLETE', 'No se puede publicar un producto con información financiera incompleta.');
        }

        if (array_key_exists('lock_version', $datos)) {
            if ($version->lock_version !== (int) $datos['lock_version']) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'La versión del producto fue modificada por otro usuario.');
            }
            $version->lock_version++;
        }

        return DB::transaction(function () use ($version, $datos, $usuarioId) {
            $versionPrevia = ProductVersion::where('product_id', $version->product_id)
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

            Cache::forget('productos:todos_vigentes');
            Cache::forget("producto:{$version->product->code}");

            return $version;
        });
    }

    public function desactivarProducto(Product $producto, string $usuarioId): Product
    {
        // Punto 65: Permitir desactivar un producto utilizado sin eliminar sus versiones
        $producto->status = BaseStatus::INACTIVE;
        $producto->updated_by = $usuarioId;
        $producto->save();

        Cache::forget('productos:todos_vigentes');
        Cache::forget("producto:{$producto->code}");

        return $producto;
    }

    public function resolver(string $code, ?Carbon $fecha = null): array
    {
        $fechaConsulta = $fecha ?? now();
        $isCurrent = ! $fecha || $fechaConsulta->diffInSeconds(now()) < 5;

        if ($isCurrent) {
            return Cache::remember("producto:{$code}", $this->calcularCacheTTL(), fn () => $this->resolverDesdeBD($code, $fechaConsulta));
        }

        return $this->resolverDesdeBD($code, $fechaConsulta);
    }

    private function resolverDesdeBD(string $code, Carbon $fecha): array
    {
        // Punto 66: Impedir utilizar productos en borrador, inactivos, vencidos o sin versión publicada
        $version = ProductVersion::whereHas('product', function ($q) use ($code) {
            $q->where('code', $code)->where('status', BaseStatus::ACTIVE); // Excluye productos inactivos
        })
            ->where('status', VersionStatus::PUBLISHED) // Excluye DRAFT e INACTIVE (borradores y descartados)
            ->where('effective_from', '<=', $fecha) // Inicio vigente
            ->where(function ($q) use ($fecha) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $fecha); // Fin vigente (no vencidos)
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if (! $version) {
            throw new BusinessException('PRODUCT_VERSION_NOT_PUBLISHED', "No existe una versión publicada y vigente para el producto activo: {$code} (Punto 66)");
        }

        return [
            'product_id' => $version->product_id,
            'version_id' => $version->id,
            'code' => $version->product->code,
            'name' => $version->name,
            'description' => $version->description,
            'nominal_amount' => $version->nominal_amount,
            'loan_commission_percentage' => $version->loan_commission_percentage,
            'simple_interest_percentage' => $version->simple_interest_percentage,
            'insurance_amount' => $version->insurance_amount,
            'fortnights_count' => $version->fortnights_count,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'effective_to' => $version->effective_to?->toIso8601String(),
            'version' => $version->version,
        ];
    }

    private function calcularCacheTTL(): Carbon
    {
        $proximaVersion = ProductVersion::where('status', VersionStatus::PUBLISHED)
            ->where('effective_from', '>', now())
            ->orderBy('effective_from', 'asc')
            ->first();

        return $proximaVersion ? $proximaVersion->effective_from : now()->addHours(24);
    }
}
