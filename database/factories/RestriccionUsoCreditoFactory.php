<?php

namespace Database\Factories;

use App\Models\LineaCredito;
use App\Models\RestriccionUsoCredito;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\User;
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
            'distributor_id' => fn (array $attributes) => LineaCredito::query()->findOrFail($attributes['credit_line_id'])->distributor_id,
            'type' => 'INITIAL_50_PERCENT',
            'status' => 'ACTIVE',
            'base_total' => bcdiv((string) $importe, '10000', 4),
            'tolerance_amount' => '0.0000',
            'configuration_version_id' => function (): string {
                $autor = User::factory()->create(['state' => 'ACTIVE']);
                $definicion = ConfigurationDefinition::create([
                    'key' => 'TEST_TOLERANCE_'.fake()->unique()->uuid(),
                    'name' => 'Tolerancia de prueba',
                    'value_type' => 'DECIMAL',
                    'status' => 'ACTIVE',
                    'created_by' => $autor->id,
                ]);

                return ConfigurationVersion::create([
                    'configuration_definition_id' => $definicion->id,
                    'version' => 1,
                    'value' => '0.0000',
                    'status' => 'PUBLISHED',
                    'effective_from' => now()->subMinute(),
                    'reason' => 'Prueba',
                    'created_by' => $autor->id,
                    'published_by' => $autor->id,
                    'published_at' => now(),
                ])->id;
            },
            'source_type' => 'DISTRIBUTOR_APPLICATION_AUTHORIZATION',
            'source_id' => fake()->uuid(),
            'activated_at' => now(),
            'created_by' => User::factory(),
            'lock_version' => 1,
        ];
    }

    public function reserved(): self
    {
        return $this->state(fn () => [
            'status' => 'RESERVED',
            'reserved_voucher_id' => fake()->uuid(),
            'reserved_at' => now(),
        ]);
    }

    public function consumed(): self
    {
        return $this->reserved()->state(fn () => [
            'status' => 'CONSUMED',
            'consumed_at' => now(),
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn () => [
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);
    }
}
