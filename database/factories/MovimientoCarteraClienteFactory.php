<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\MovimientoCarteraCliente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MovimientoCarteraCliente> */
class MovimientoCarteraClienteFactory extends Factory
{
    protected $model = MovimientoCarteraCliente::class;

    public function definition(): array
    {
        $tipo = fake()->randomElement(['DEBT', 'PAYMENT', 'PARTIAL_PAYMENT', 'NOTE', 'ADJUSTMENT_INCREASE']);

        return [
            'client_id' => Cliente::factory(),
            'distributor_id' => Distribuidora::factory(),
            'entry_type' => $tipo,
            'amount' => $tipo === 'NOTE' ? null : fake()->randomElement(['100.0000', '250.5000', '1000.0000']),
            'informational_status' => fake()->optional()->randomElement(['PENDING', 'PARTIALLY_PAID', 'PAID']),
            'occurred_at' => now()->subDays(fake()->numberBetween(0, 30)),
            'due_date' => fake()->boolean(70) ? now()->addDays(fake()->numberBetween(1, 30))->format('Y-m-d') : null,
            'note' => $tipo === 'NOTE' ? fake()->sentence() : null,
            'recorded_by' => User::factory(),
            'lock_version' => 1,
        ];
    }
}
