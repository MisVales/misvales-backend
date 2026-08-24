<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoVale;
use App\Enums\TipoVale;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\LineaCredito;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Models\Vale;
use App\Services\Vale\CalculadorFinancieroVale;
use App\Services\Vale\ConfiguracionFinancieraVale;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de demostración para la consulta de vales.
 *
 * Ejecutar manualmente con: php artisan db:seed --class=ValesEjemploSeeder
 */
final class ValesEjemploSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $usuario = $this->usuarioDemo();
            $sucursal = $this->sucursalDemo($usuario);
            $distribuidora = $this->distribuidoraDemo($usuario, $sucursal);
            $lineaCredito = LineaCredito::query()->firstOrCreate(
                ['distributor_id' => $distribuidora->id],
                ['total_authorized' => '100000.0000', 'used_balance' => '0.0000', 'lock_version' => 1],
            );
            $categoria = $this->categoriaDemo($usuario);

            AsignacionCategoriaDistribuidora::query()->firstOrCreate(
                ['distributor_id' => $distribuidora->id, 'ends_at' => null],
                [
                    'category_version_id' => $categoria->id,
                    'starts_at' => now()->subDay(),
                    'assigned_by' => $usuario->id,
                    'reason' => 'Categoría para vales de demostración',
                ],
            );

            $importes = [5000, 10000, 20000];
            foreach (range(1, 20) as $indice) {
                $importe = $importes[($indice - 1) % count($importes)];
                $cliente = $this->clienteDemo($usuario, $importe, $indice);
                AsignacionClienteDistribuidora::query()->firstOrCreate(
                    ['client_id' => $cliente->id, 'distributor_id' => $distribuidora->id, 'ends_at' => null],
                    [
                        'branch_id' => $sucursal->id,
                        'starts_at' => now()->subDay(),
                        'assigned_by' => $usuario->id,
                        'reason' => 'Cliente para vale de demostración',
                    ],
                );

                $versionProducto = $this->productoDemo($usuario, $importe);
                $this->crearVale($usuario, $cliente, $distribuidora, $lineaCredito, $categoria, $versionProducto, $importe, $indice);
            }
        });
    }

    private function usuarioDemo(): User
    {
        return User::query()->firstOrCreate(
            ['normalized_email' => 'vales.demo@misvales.test'],
            [
                'name' => 'Usuario de demostración',
                'email' => 'vales.demo@misvales.test',
                'password' => Hash::make('password'),
                'state' => 'ACTIVE',
                'email_verified_at' => now(),
            ],
        );
    }

    private function sucursalDemo(User $usuario): Branch
    {
        return Branch::query()->firstOrCreate(
            ['code' => 'DEMO-VALES'],
            [
                'name' => 'Sucursal demostración de vales',
                'status' => 'ACTIVE',
                'is_headquarters' => false,
                'lock_version' => 0,
                'created_by' => $usuario->id,
            ],
        );
    }

    private function distribuidoraDemo(User $usuario, Branch $sucursal): Distribuidora
    {
        $solicitud = DistributorApplication::query()->firstOrCreate(
            ['application_number' => sprintf('SOL-%s-900001', now()->year)],
            [
                'branch_id' => $sucursal->id,
                'coordinator_id' => $usuario->id,
                'created_by' => $usuario->id,
                'status' => 'DRAFT',
                'section_declarations' => [],
                'lock_version' => 1,
            ],
        );

        $distribuidora = Distribuidora::query()->firstOrNew(['user_id' => $usuario->id]);
        if (! $distribuidora->exists) {
            $distribuidora->forceFill([
                'application_id' => $solicitud->id,
                'distributor_number' => sprintf('DIS-%s-900001', now()->year),
                'branch_id' => $sucursal->id,
                'status' => 'ACTIVE',
                'activated_at' => now(),
                'activated_by' => $usuario->id,
                'lock_version' => 1,
            ])->save();
        }

        return $distribuidora;
    }

    private function categoriaDemo(User $usuario): CategoryVersion
    {
        $categoria = Category::query()->firstOrNew(['code' => 'CAT-DEMO-VALES']);
        if (! $categoria->exists) {
            $categoria->forceFill([
                'status' => 'ACTIVE',
                'lock_version' => 0,
                'created_by' => $usuario->id,
            ])->save();
        }

        return CategoryVersion::query()->firstOrCreate(
            ['category_id' => $categoria->id, 'version' => 1],
            [
                'name' => 'Categoría demostración',
                'profit_percentage' => '0.050000',
                'status' => 'PUBLISHED',
                'effective_from' => now()->subDay(),
                'reason' => 'Datos de demostración',
                'created_by' => $usuario->id,
                'published_by' => $usuario->id,
                'published_at' => now(),
            ],
        );
    }

    private function clienteDemo(User $usuario, int $importe, int $indice): Cliente
    {
        $numero = $indice <= 3
            ? sprintf('CLI-%s-9%05d', now()->year, intdiv($importe, 1000))
            : sprintf('CLI-%s-8%05d', now()->year, $indice);

        $cliente = Cliente::query()->firstOrNew(['client_number' => $numero]);
        if (! $cliente->exists) {
            $cliente->forceFill([
                'first_name' => 'Cliente',
                'first_last_name' => 'Demostración',
                'second_last_name' => "{$importe}-{$indice}",
                'curp_ciphertext' => Crypt::encryptString("DEMO{$importe}{$indice}CURP"),
                'curp_hmac' => hash('sha256', "demo-curp-{$importe}-{$indice}"),
                'rfc_ciphertext' => Crypt::encryptString("DEMO{$importe}{$indice}RFC"),
                'rfc_hmac' => hash('sha256', "demo-rfc-{$importe}-{$indice}"),
                'birth_date' => '1990-01-01',
                'birth_place' => 'Monterrey',
                'birth_state' => 'Nuevo León',
                'birth_city' => 'Monterrey',
                'official_id_type' => 'INE',
                'created_by' => $usuario->id,
                'lock_version' => 1,
            ])->save();
        }

        return $cliente;
    }

    private function productoDemo(User $usuario, int $importe): ProductVersion
    {
        $producto = Product::query()->firstOrNew(['code' => "VAL-DEMO-{$importe}"]);
        if (! $producto->exists) {
            $producto->forceFill([
                'status' => 'ACTIVE',
                'lock_version' => 0,
                'created_by' => $usuario->id,
            ])->save();
        }

        return ProductVersion::query()->firstOrCreate(
            ['product_id' => $producto->id, 'version' => 1],
            [
                'name' => "Vale de $".number_format($importe),
                'nominal_amount' => number_format($importe, 4, '.', ''),
                                'status' => 'PUBLISHED',
                'effective_from' => now()->subDay(),
                'reason' => 'Datos de demostración',
                'created_by' => $usuario->id,
                'published_by' => $usuario->id,
                'published_at' => now(),
            ],
        );
    }

    private function crearVale(
        User $usuario,
        Cliente $cliente,
        Distribuidora $distribuidora,
        LineaCredito $lineaCredito,
        CategoryVersion $categoria,
        ProductVersion $versionProducto,
        int $importe,
        int $indice,
    ): void {
        $folio = $indice <= 3 ? "DEMO-VAL-{$importe}" : sprintf('DEMO-VAL-%02d-%s', $indice, $importe);
        if (Vale::query()->where('folio', $folio)->exists()) {
            return;
        }

        $condiciones = app(ConfiguracionFinancieraVale::class)->resolver()['values'];
        $calculo = app(CalculadorFinancieroVale::class)->calcular(
            number_format($importe, 4, '.', ''),
            $condiciones['loan_commission_percentage'],
            $condiciones['simple_interest_percentage'],
            4,
            $condiciones['insurance_amount'],
            (string) $categoria->profit_percentage,
        );

        $vale = Vale::query()->create([
            'folio' => $folio,
            'type' => TipoVale::PREVALE,
            'status' => EstadoVale::GENERADO,
            'client_id' => $cliente->id,
            'distributor_id' => $distribuidora->id,
            'branch_id' => $distribuidora->branch_id,
            'credit_line_id' => $lineaCredito->id,
            'product_id' => $versionProducto->product_id,
            'product_version_id' => $versionProducto->id,
            'category_version_id' => $categoria->id,
            ...collect($calculo)->only([
                'capital', 'loan_commission_percentage', 'loan_commission_amount',
                'simple_interest_percentage', 'fortnights_count', 'insurance_amount',
                'interest_total', 'misvales_total', 'misvales_payment_per_fortnight',
                'distributor_profit_percentage', 'distributor_profit_total',
                'distributor_profit_per_fortnight', 'client_payment_per_fortnight',
                'client_total',
            ])->all(),
            'financial_snapshot' => [
                'source' => 'ValesEjemploSeeder',
                'product_version_id' => $versionProducto->id,
                'category_version_id' => $categoria->id,
                'calculation' => collect($calculo)->except('installments')->all(),
            ],
            'created_by' => $usuario->id,
            'generated_at' => now(),
        ]);

        $vale->parcialidades()->createMany(array_map(
            static fn (array $parcialidad): array => $parcialidad + ['due_at' => null],
            $calculo['installments'],
        ));
    }
}
