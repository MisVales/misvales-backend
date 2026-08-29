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
                'loan_commission_percentage' => isset($datos['loan_commission_percentage']) ? bcadd((string) $datos['loan_commission_percentage'], '0', 6) : null,
                'simple_interest_percentage' => isset($datos['simple_interest_percentage']) ? bcadd((string) $datos['simple_interest_percentage'], '0', 6) : null,
                'insurance_amount' => isset($datos['insurance_amount']) ? bcadd((string) $datos['insurance_amount'], '0', 4) : null,
                'fortnights_count' => isset($datos['fortnights_count']) ? (int) $datos['fortnights_count'] : null,
                'late_fee_amount' => isset($datos['late_fee_amount']) ? bcadd((string) $datos['late_fee_amount'], '0', 4) : null,
                'created_by' => $usuarioId,
            ]);

            $this->crearVersion($producto, $datos, $usuarioId);

            return $producto->load('versions');
        });
    }

    public function crearVersion(Product $producto, array $datos, string $usuarioId): ProductVersion
    {
        $ultimaVersion = $producto->versions()->max('version') ?? 0;

        $effectiveFrom = now('America/Monterrey')->setTimezone('UTC');

        return ProductVersion::create([
            'product_id' => $producto->id,
            'version' => $ultimaVersion + 1,
            'name' => $datos['name'],
            'description' => $datos['description'] ?? null,
            'nominal_amount' => $this->normalizarMonto($datos['nominal_amount']),
            'status' => VersionStatus::DRAFT,
            'effective_from' => $effectiveFrom,
            'reason' => $datos['reason'],
            'created_by' => $usuarioId,
        ]);
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
        if (array_key_exists('reason', $datos)) {
            $version->reason = $datos['reason'];
        }
        $version->save();

        return $version;
    }

    public function publicarVersion(ProductVersion $version, array $datos, string $usuarioId): ProductVersion
    {
        if ($version->status !== VersionStatus::DRAFT) {
            throw new BusinessException('PRODUCT_VERSION_IMMUTABLE', 'Solo las versiones en DRAFT pueden ser publicadas.');
        }

        if (is_null($version->name) || is_null($version->nominal_amount)) {
            throw new BusinessException('PRODUCT_INCOMPLETE', 'No se puede publicar un producto sin nombre e importe nominal.');
        }

        $producto = $version->product;
        if (
            is_null($producto->loan_commission_percentage) ||
            is_null($producto->simple_interest_percentage) ||
            is_null($producto->insurance_amount) ||
            is_null($producto->fortnights_count) ||
            is_null($producto->late_fee_amount)
        ) {
            throw new BusinessException('PRODUCT_FINANCIAL_CONFIG_INCOMPLETE', 'No se puede publicar una versión sin configurar las condiciones financieras del producto (comisión, interés, seguro, quincenas, recargo).');
        }

        if (array_key_exists('lock_version', $datos)) {
            if ($version->lock_version !== (int) $datos['lock_version']) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'La versión del producto fue modificada por otro usuario.');
            }
            $version->lock_version++;
        }

        return DB::transaction(function () use ($version, $datos, $usuarioId) {
            $publicadaEn = now();
            $versionPrevia = ProductVersion::where('product_id', $version->product_id)
                ->where('status', VersionStatus::PUBLISHED)
                ->whereNull('effective_to')
                ->lockForUpdate() // Evitar vigencias concurrentes
                ->first();

            if ($versionPrevia) {
                if ($publicadaEn->lessThanOrEqualTo($versionPrevia->effective_from)) {
                    throw new BusinessException('OVERLAPPING_VALIDITY', 'La vigencia debe ser estrictamente posterior a la versión publicada actual.');
                }

                $versionPrevia->effective_to = $publicadaEn;
                $versionPrevia->status = VersionStatus::INACTIVE;

                $versionPrevia->save();
            }

            $version->effective_from = $publicadaEn;
            $version->status = VersionStatus::PUBLISHED;
            $version->reason .= "\n[Publicación]: ".$datos['reason'];
            $version->published_by = $usuarioId;
            $version->published_at = $publicadaEn;
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
