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
use Illuminate\Support\Str;

class ProductoServicio
{
    public function crearProducto(array $datos, string $usuarioId): Product
    {
        return DB::transaction(function () use ($datos, $usuarioId) {
            $producto = Product::create([
                'code' => 'VAL-'.Str::upper((string) Str::ulid()),
                'status' => BaseStatus::ACTIVE,
                'created_by' => $usuarioId,
            ]);

            $datos['reason'] = 'Alta automática del producto.';
            $this->crearVersion($producto, $datos, $usuarioId);

            return $producto->load('versions');
        });
    }

    public function crearVersion(Product $producto, array $datos, string $usuarioId): ProductVersion
    {
        return DB::transaction(function () use ($producto, $datos, $usuarioId): ProductVersion {
            $producto = Product::query()->lockForUpdate()->findOrFail($producto->id);

            $ultimaVersion = $producto->versions()->max('version') ?? 0;
            $effectiveFrom = now('America/Monterrey')->setTimezone('UTC');
            $condiciones = $this->normalizarCondicionesFinancieras($datos, $producto, true);

            return ProductVersion::create([
                'product_id' => $producto->id,
                'version' => $ultimaVersion + 1,
                'name' => $datos['name'],
                'description' => $datos['description'] ?? null,
                'nominal_amount' => $this->normalizarMonto($datos['nominal_amount']),
                'status' => VersionStatus::DRAFT,
                'effective_from' => $effectiveFrom,
                'reason' => $datos['reason'] ?? 'Nueva versión automática del producto.',
                'created_by' => $usuarioId,
                ...$condiciones,
            ]);
        });
    }

    /**
     * Las condiciones se editan en la versión borrador. Una versión nueva hereda
     * las condiciones publicadas cuando no las recibe; una edición de borrador
     * sólo cambia los campos que sí se recibieron.
     *
     * @return array<string, string|int|null>
     */
    private function normalizarCondicionesFinancieras(array $datos, Product|ProductVersion|null $origen = null, bool $heredar = false): array
    {
        $atributos = [];

        foreach (['loan_commission_percentage', 'simple_interest_percentage'] as $campo) {
            if (array_key_exists($campo, $datos) || $heredar) {
                $valor = array_key_exists($campo, $datos) ? $datos[$campo] : $origen?->{$campo};
                $atributos[$campo] = is_null($valor) ? null : bcadd((string) $valor, '0', 6);
            }
        }

        foreach (['insurance_amount', 'late_fee_amount'] as $campo) {
            if (array_key_exists($campo, $datos) || $heredar) {
                $valor = array_key_exists($campo, $datos) ? $datos[$campo] : $origen?->{$campo};
                $atributos[$campo] = is_null($valor) ? null : bcadd((string) $valor, '0', 4);
            }
        }

        if (array_key_exists('fortnights_count', $datos) || $heredar) {
            $valor = array_key_exists('fortnights_count', $datos) ? $datos['fortnights_count'] : $origen?->fortnights_count;
            $atributos['fortnights_count'] = is_null($valor) ? null : (int) $valor;
        }

        return $atributos;
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
        foreach ($this->normalizarCondicionesFinancieras($datos) as $campo => $valor) {
            $version->{$campo} = $valor;
        }
        $version->save();

        return $version;
    }

    public function publicarVersion(ProductVersion $version, array $datos, string $usuarioId): ProductVersion
    {
        return DB::transaction(function () use ($version, $datos, $usuarioId) {
            $version = ProductVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($version->status !== VersionStatus::DRAFT) {
                throw new BusinessException('PRODUCT_VERSION_IMMUTABLE', 'Solo las versiones en DRAFT pueden ser publicadas.');
            }
            if (is_null($version->name) || is_null($version->nominal_amount)) {
                throw new BusinessException('PRODUCT_INCOMPLETE', 'No se puede publicar un producto sin nombre e importe nominal.');
            }
            if ($version->lock_version !== (int) $datos['lock_version']) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'La versión del producto fue modificada por otro usuario.', 409);
            }
            $this->asegurarCondicionesFinancierasCompletas($version);

            $producto = Product::query()->lockForUpdate()->findOrFail($version->product_id);
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

            $producto->fill($this->normalizarCondicionesFinancieras($version->getAttributes()));
            $producto->updated_by = $usuarioId;
            $producto->save();

            $version->effective_from = $publicadaEn;
            $version->status = VersionStatus::PUBLISHED;
            $version->reason .= "\n[Publicación]: ".$datos['reason'];
            $version->published_by = $usuarioId;
            $version->published_at = $publicadaEn;
            $version->lock_version++;
            $version->save();

            Cache::forget('productos:todos_vigentes');
            Cache::forget("producto:{$producto->code}");

            return $version;
        });
    }

    private function asegurarCondicionesFinancierasCompletas(ProductVersion $version): void
    {
        $campos = [
            'loan_commission_percentage' => 'comisión',
            'simple_interest_percentage' => 'interés',
            'insurance_amount' => 'seguro',
            'fortnights_count' => 'quincenas',
            'late_fee_amount' => 'recargo',
        ];
        $faltantes = array_values(array_filter(
            $campos,
            static fn (string $etiqueta, string $campo): bool => is_null($version->{$campo}),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($faltantes !== []) {
            throw new BusinessException(
                'PRODUCT_FINANCIAL_CONFIG_INCOMPLETE',
                'No se puede publicar una versión sin configurar: '.implode(', ', $faltantes).'.',
                422,
            );
        }
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
