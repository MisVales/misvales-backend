<?php

namespace Database\Factories;

use App\Models\Distribuidora;
use App\Models\LineaCredito;
use App\Models\RestriccionUsoCredito;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LineaCredito> */
class LineaCreditoFactory extends Factory
{
    protected $model = LineaCredito::class;

    public function definition(): array
    {
        $importe = fake()->numberBetween(10000000, 1000000000);

        return [
            'distributor_id' => Distribuidora::factory(),
            'total_authorized' => bcdiv((string) $importe, '10000', 4),
            'used_balance' => '0.0000',
            'lock_version' => 1,
        ];
    }

    public function withActiveInitialRestriction(): self
    {
        return $this->has(
            RestriccionUsoCredito::factory()->state(function (array $attributes, LineaCredito $linea) {
                return [
                    'type' => 'INITIAL_50_PERCENT',
                    'status' => 'ACTIVE',
                    'base_total' => $linea->total_authorized,
                ];
            }),
            'restricciones'
        );
    }

    public function withActivePostIncreaseRestriction(): self
    {
        return $this->has(
            RestriccionUsoCredito::factory()->state(function (array $attributes, LineaCredito $linea) {
                return [
                    'type' => 'POST_INCREASE_50_PERCENT',
                    'status' => 'ACTIVE',
                    'base_total' => $linea->total_authorized,
                ];
            }),
            'restricciones'
        );
    }
}
