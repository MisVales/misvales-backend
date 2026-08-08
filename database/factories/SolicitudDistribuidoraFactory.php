<?php

namespace Database\Factories;

use App\Models\SolicitudDistribuidora;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SolicitudDistribuidora> */
final class SolicitudDistribuidoraFactory extends Factory
{
    protected $model = SolicitudDistribuidora::class;

    public function definition(): array
    {
        return [
            'id' => fake()->uuid(),
            'application_number' => sprintf('SOL-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'status' => 'DRAFT',
            'section_declarations' => array_fill_keys([
                'personal_data', 'residence', 'partner', 'children', 'family_references',
                'vehicles', 'assets', 'liabilities', 'employment', 'commercial_credits',
            ], 'PENDING'),
            'lock_version' => 1,
        ];
    }
}
