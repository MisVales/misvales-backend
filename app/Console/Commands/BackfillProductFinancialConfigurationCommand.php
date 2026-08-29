<?php

namespace App\Console\Commands;

use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Services\ConfiguracionServicio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BackfillProductFinancialConfigurationCommand extends Command
{
    protected $signature = 'products:backfill-financial-config
        {--terms-file= : CSV con encabezados code,fortnights_count para los productos que aún no tienen plazo}
        {--dry-run : Valida insumos y muestra los productos a actualizar sin escribir cambios}';

    protected $description = 'Copia condiciones financieras globales históricas a productos activos sin sobrescribir condiciones propias.';

    /** @var array<string, array{key: string, scale: int}> */
    private const LEGACY_GLOBALS = [
        'loan_commission_percentage' => ['key' => 'LOAN_COMMISSION_PERCENTAGE', 'scale' => 6],
        'simple_interest_percentage' => ['key' => 'INTEREST_RATE_PER_FORTNIGHT', 'scale' => 6],
        'insurance_amount' => ['key' => 'VOUCHER_INSURANCE_AMOUNT', 'scale' => 4],
        'late_fee_amount' => ['key' => 'LATE_FEE_AMOUNT', 'scale' => 4],
    ];

    public function handle(ConfiguracionServicio $configuraciones): int
    {
        $productos = Product::query()
            ->where('status', BaseStatus::ACTIVE->value)
            ->orderBy('code')
            ->get();

        if ($productos->isEmpty()) {
            $this->info('No hay productos activos que migrar.');

            return self::SUCCESS;
        }

        try {
            $camposGlobalesPendientes = array_keys(array_filter(
                self::LEGACY_GLOBALS,
                static fn (array $definicion, string $campo): bool => $productos->contains(
                    static fn (Product $producto): bool => is_null($producto->{$campo}),
                ),
                ARRAY_FILTER_USE_BOTH,
            ));
            $valoresGlobales = $this->resolverValoresGlobales($configuraciones, $camposGlobalesPendientes);
            $plazos = $this->resolverPlazos($productos->filter(
                static fn (Product $producto): bool => is_null($producto->fortnights_count),
            )->pluck('code')->all());
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $actualizables = $productos->filter(function (Product $producto) use ($plazos): bool {
            return is_null($producto->loan_commission_percentage)
                || is_null($producto->simple_interest_percentage)
                || is_null($producto->insurance_amount)
                || is_null($producto->late_fee_amount)
                || (is_null($producto->fortnights_count) && isset($plazos[$producto->code]));
        });

        if ($this->option('dry-run')) {
            $this->table(['Código', 'Requiere plazo', 'Requiere valores globales'], $actualizables->map(
                static fn (Product $producto): array => [
                    $producto->code,
                    is_null($producto->fortnights_count) ? 'sí' : 'no',
                    collect(self::LEGACY_GLOBALS)->contains(
                        static fn (array $definition, string $field): bool => is_null($producto->{$field}),
                    ) ? 'sí' : 'no',
                ],
            )->all());
            $this->info("Validación correcta. Se actualizarían {$actualizables->count()} producto(s).");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($productos, $plazos, $valoresGlobales): void {
            $bloqueados = Product::query()
                ->whereIn('id', $productos->pluck('id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $versionesPublicadas = ProductVersion::query()
                ->whereIn('product_id', $productos->pluck('id'))
                ->where('status', VersionStatus::PUBLISHED)
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            foreach ($productos as $productoOriginal) {
                /** @var Product $producto */
                $producto = $bloqueados->get($productoOriginal->id);
                $cambios = [];

                foreach (self::LEGACY_GLOBALS as $campo => $definicion) {
                    if (is_null($producto->{$campo})) {
                        $cambios[$campo] = $valoresGlobales[$campo];
                    }
                }
                if (is_null($producto->fortnights_count)) {
                    $cambios['fortnights_count'] = $plazos[$producto->code];
                }

                if ($cambios !== []) {
                    $producto->forceFill($cambios)->save();
                }

                /** @var ProductVersion|null $version */
                $version = $versionesPublicadas->get($producto->id);
                if ($version === null) {
                    continue;
                }

                $cambiosVersion = [];
                foreach ([
                    'loan_commission_percentage',
                    'simple_interest_percentage',
                    'insurance_amount',
                    'fortnights_count',
                    'late_fee_amount',
                ] as $campo) {
                    if (is_null($version->{$campo}) && ! is_null($producto->{$campo})) {
                        $cambiosVersion[$campo] = $producto->{$campo};
                    }
                }
                if ($cambiosVersion !== []) {
                    $version->forceFill($cambiosVersion)->save();
                }
            }
        });

        Cache::forget('productos:todos_vigentes');
        foreach ($productos as $producto) {
            Cache::forget("producto:{$producto->code}");
        }

        $this->info("Migración correcta. Se revisaron {$productos->count()} producto(s) activos; {$actualizables->count()} requirieron actualización.");

        return self::SUCCESS;
    }

    /**
     * @param list<string> $campos
     * @return array<string, string>
     */
    private function resolverValoresGlobales(ConfiguracionServicio $configuraciones, array $campos): array
    {
        $valores = [];

        foreach ($campos as $campo) {
            $definicion = self::LEGACY_GLOBALS[$campo];
            try {
                $valor = $configuraciones->resolver($definicion['key'])['value'] ?? null;
            } catch (\Throwable) {
                throw new RuntimeException("Falta una configuración global publicada para {$definicion['key']}. No se modificó ningún producto.");
            }
            if (is_array($valor) || ! is_numeric($valor) || bccomp((string) $valor, '0', $definicion['scale']) < 0) {
                throw new RuntimeException("La configuración global {$definicion['key']} no tiene un valor numérico válido. No se modificó ningún producto.");
            }

            $valores[$campo] = bcadd((string) $valor, '0', $definicion['scale']);
        }

        return $valores;
    }

    /** @param list<string> $codigosPendientes
     *  @return array<string, int>
     */
    private function resolverPlazos(array $codigosPendientes): array
    {
        if ($codigosPendientes === []) {
            return [];
        }

        $archivo = $this->option('terms-file');
        if (! is_string($archivo) || $archivo === '' || ! is_readable($archivo)) {
            throw new RuntimeException('Falta --terms-file legible. Debe contener code,fortnights_count para cada producto activo sin plazo.');
        }

        $manejador = fopen($archivo, 'rb');
        if ($manejador === false) {
            throw new RuntimeException('No fue posible abrir el archivo de plazos.');
        }

        try {
            $encabezados = fgetcsv($manejador);
            if ($encabezados === false || array_map('trim', $encabezados) !== ['code', 'fortnights_count']) {
                throw new RuntimeException('El CSV de plazos debe tener exactamente los encabezados: code,fortnights_count.');
            }

            $plazos = [];
            $linea = 1;
            while (($fila = fgetcsv($manejador)) !== false) {
                $linea++;
                if ($fila === [null] || $fila === []) {
                    continue;
                }
                if (count($fila) !== 2) {
                    throw new RuntimeException("El CSV de plazos tiene una fila inválida en la línea {$linea}.");
                }
                [$codigo, $plazo] = array_map(static fn ($valor): string => trim((string) $valor), $fila);
                if ($codigo === '' || isset($plazos[$codigo]) || filter_var($plazo, FILTER_VALIDATE_INT) === false || (int) $plazo < 1) {
                    throw new RuntimeException("El CSV de plazos tiene un código o plazo inválido en la línea {$linea}.");
                }
                $plazos[$codigo] = (int) $plazo;
            }
        } finally {
            fclose($manejador);
        }

        sort($codigosPendientes);
        $codigosArchivo = array_keys($plazos);
        sort($codigosArchivo);
        if ($codigosPendientes !== $codigosArchivo) {
            $faltantes = array_values(array_diff($codigosPendientes, $codigosArchivo));
            $sobrantes = array_values(array_diff($codigosArchivo, $codigosPendientes));
            $detalle = [];
            if ($faltantes !== []) {
                $detalle[] = 'faltan: '.implode(', ', $faltantes);
            }
            if ($sobrantes !== []) {
                $detalle[] = 'sobran: '.implode(', ', $sobrantes);
            }

            throw new RuntimeException('El CSV de plazos no coincide con los productos pendientes ('.implode('; ', $detalle).'). No se modificó ningún producto.');
        }

        return $plazos;
    }
}
