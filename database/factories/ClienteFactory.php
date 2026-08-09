<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/** @extends Factory<Cliente> */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    public function definition(): array
    {
        $fechaNacimiento = now()->subYears(fake()->numberBetween(18, 70));
        $identificador = strtoupper(
            fake()->lexify('????').$fechaNacimiento->format('ymd')
            .fake()->randomElement(['H', 'M']).fake()->lexify('?????').fake()->bothify('?8'),
        );
        $rfc = strtoupper(fake()->unique()->bothify('????######???'));

        return [
            'client_number' => sprintf('CLI-%d-%06d', now()->year, fake()->unique()->numberBetween(1, 999999)),
            'first_name' => fake()->firstName(),
            'first_last_name' => fake()->lastName(),
            'second_last_name' => fake()->optional()->lastName(),
            'curp_ciphertext' => Crypt::encryptString($identificador),
            'curp_hmac' => hash('sha256', 'synthetic-curp-'.$identificador),
            'rfc_ciphertext' => Crypt::encryptString($rfc),
            'rfc_hmac' => hash('sha256', 'synthetic-rfc-'.$rfc),
            'birth_date' => $fechaNacimiento->format('Y-m-d'),
            'birth_place' => fake()->city(),
            'birth_state' => fake()->state(),
            'birth_city' => fake()->city(),
            'official_id_type' => fake()->randomElement(['INE', 'PASSPORT', 'PROFESSIONAL_LICENSE', 'OTHER']),
            'official_id_number_ciphertext' => Crypt::encryptString(fake()->unique()->numerify('#############')),
            'official_id_number_hmac' => hash('sha256', fake()->unique()->uuid()),
            'created_by' => User::factory(),
            'lock_version' => 1,
        ];
    }
}
