<?php

namespace Database\Factories;

use App\Models\LineaCredito;
use App\Models\RestriccionUsoCredito;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RestriccionUsoCredito> */
class RestriccionUsoCreditoFactory extends Factory
{
    protected $model = RestriccionUsoCredito::class;

    public function definition(): array
    {
        $importe = fake()->numberBetween(10000000, 1000000000);

        return [
            'credit_line_id' => LineaCredito::factory(),
            'type' => 'INITIAL_50_PERCENT',
            'status' => 'ACTIVE',
            'base_total' => number_format($importe / 10000, 4, '.', ''),
            'consumed_at' => null,
            'voucher_id' => null,
        ];
    }
}
