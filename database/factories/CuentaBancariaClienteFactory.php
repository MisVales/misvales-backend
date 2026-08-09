<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\CuentaBancariaCliente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/** @extends Factory<CuentaBancariaCliente> */
class CuentaBancariaClienteFactory extends Factory
{
    protected $model = CuentaBancariaCliente::class;

    public function definition(): array
    {
        $cuenta = fake()->unique()->numerify('############');
        $clabe = fake()->unique()->numerify('##################');

        return [
            'client_id' => Cliente::factory(),
            'bank_name' => 'Banco sintético '.fake()->randomNumber(3),
            'account_holder_name' => fake()->name(),
            'account_number_ciphertext' => Crypt::encryptString($cuenta),
            'account_number_hmac' => hash('sha256', 'synthetic-account-'.$cuenta),
            'clabe_ciphertext' => Crypt::encryptString($clabe),
            'clabe_hmac' => hash('sha256', 'synthetic-clabe-'.$clabe),
            'is_current' => true,
            'starts_at' => now(),
            'created_by' => User::factory(),
            'lock_version' => 1,
        ];
    }
}
