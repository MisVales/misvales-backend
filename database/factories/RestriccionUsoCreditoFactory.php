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
            'distributor_id' => \App\Models\Distribuidora::factory(),
            'type' => 'INITIAL_50_PERCENT',
            'status' => 'ACTIVE',
            'base_total' => number_format($importe / 10000, 4, '.', ''),
            'tolerance_amount' => '0.0000',
            'configuration_version_id' => 'v1.0.0',
            'source_type' => 'DISTRIBUTOR_APPLICATION_AUTHORIZATION',
            'source_id' => fake()->uuid(),
            'activated_at' => now(),
            'created_by' => \App\Models\User::factory(),
        ];
    }
}
