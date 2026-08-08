<?php

namespace Database\Factories;

use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MovimientoLineaCredito> */
class MovimientoLineaCreditoFactory extends Factory
{
    protected $model = MovimientoLineaCredito::class;

    public function definition(): array
    {
        $importe = number_format(fake()->numberBetween(10000000, 1000000000) / 10000, 4, '.', '');

        return [
            'credit_line_id' => LineaCredito::factory(),
            'type' => 'INITIAL_AUTHORIZATION',
            'amount' => $importe,
            'balance_before' => '0.0000',
            'balance_after' => $importe,
            'source_type' => 'DISTRIBUTOR_APPLICATION_AUTHORIZATION',
            'source_id' => fake()->uuid(),
            'created_by' => User::factory(),
        ];
    }
}
