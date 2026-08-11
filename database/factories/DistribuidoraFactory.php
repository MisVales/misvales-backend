<?php

namespace Database\Factories;

use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Distribuidora> */
class DistribuidoraFactory extends Factory
{
    protected $model = Distribuidora::class;

    public function definition(): array
    {
        return [
            'application_id' => DistributorApplication::factory(),
            'user_id' => User::factory()->state(['password' => null, 'state' => 'PENDING_ACTIVATION']),
            'distributor_number' => sprintf('DIS-%d-%06d', now()->year, fake()->unique()->numberBetween(1, 999999)),
            'branch_id' => fn (array $atributos) => DistributorApplication::query()
                ->findOrFail($atributos['application_id'])->branch_id,
            'status' => 'PENDING_ACTIVATION',
            'lock_version' => 1,
        ];
    }

    public function active(): self
    {
        return $this->state(fn () => [
            'status' => 'ACTIVE',
            'activated_at' => now(),
            'activated_by' => User::factory()->state(['state' => 'ACTIVE']),
        ]);
    }

    public function disabledWithActivationHistory(): self
    {
        return $this->state(fn () => [
            'status' => 'DISABLED',
            'activated_at' => now()->subDay(),
            'activated_by' => User::factory()->state(['state' => 'ACTIVE']),
        ]);
    }
}
