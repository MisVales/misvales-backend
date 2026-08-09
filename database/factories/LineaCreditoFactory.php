<?php

namespace Database\Factories;

use App\Models\Distribuidora;
use App\Models\LineaCredito;
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
            'total_authorized' => number_format($importe / 10000, 4, '.', ''),
            'used_balance' => '0.0000',
            'lock_version' => 1,
        ];
    }
}
