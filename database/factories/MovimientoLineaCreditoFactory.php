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
            'distributor_id' => \App\Models\Distribuidora::factory(),
            'sequence' => fake()->unique()->numberBetween(1, 1000),
            'type' => 'INITIAL_AUTHORIZATION',
            'amount' => $importe,
            'total_authorized_before' => '0.0000',
            'total_authorized_after' => $importe,
            'used_balance_before' => '0.0000',
            'used_balance_after' => '0.0000',
            'source_type' => 'DISTRIBUTOR_APPLICATION_AUTHORIZATION',
            'source_id' => fake()->uuid(),
            'reason' => fake()->sentence(),
            'performed_by' => User::factory(),
            'authorized_by' => null,
            'occurred_at' => now(),
        ];
    }
}
