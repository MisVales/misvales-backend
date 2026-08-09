<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\DomicilioCliente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DomicilioCliente> */
class DomicilioClienteFactory extends Factory
{
    protected $model = DomicilioCliente::class;

    public function definition(): array
    {
        return [
            'client_id' => Cliente::factory(),
            'is_current' => true,
            'street' => fake()->streetName(),
            'exterior_number' => fake()->buildingNumber(),
            'interior_number' => fake()->optional()->numerify('##'),
            'neighborhood' => fake()->citySuffix(),
            'postal_code' => fake()->postcode(),
            'municipality' => fake()->city(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => 'MX',
            'normalized_fingerprint_hmac' => hash('sha256', 'synthetic-address-'.fake()->unique()->uuid()),
            'starts_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
