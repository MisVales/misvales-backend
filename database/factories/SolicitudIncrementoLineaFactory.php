<?php

namespace Database\Factories;

use App\Enums\EstadoSolicitudIncremento;
use App\Models\LineaCredito;
use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SolicitudIncrementoLinea> */
class SolicitudIncrementoLineaFactory extends Factory
{
    protected $model = SolicitudIncrementoLinea::class;

    public function definition(): array
    {
        return [
            'request_number' => 'INC-2026-' . fake()->unique()->numerify('######'),
            'distributor_id' => \App\Models\Distribuidora::factory(),
            'credit_line_id' => LineaCredito::factory(),
            'branch_id' => \App\Models\Branch::factory(),
            'coordinator_id' => User::factory(),
            'status' => EstadoSolicitudIncremento::REQUESTED,
            'requested_amount' => number_format(fake()->numberBetween(1000, 10000), 4, '.', ''),
            'recommended_amount' => null,
            'authorized_amount' => null,
            'line_total_at_request' => '10000.0000',
            'used_balance_at_request' => '0.0000',
            'available_balance_at_request' => '10000.0000',
            'request_reason' => fake()->sentence(),
            'requested_by' => User::factory(),
            'requested_at' => now(),
            'manager_decided_by' => null,
            'manager_decided_at' => null,
            'restriction_id' => null,
            'lock_version' => 1,
        ];
    }

    public function requested(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => EstadoSolicitudIncremento::REQUESTED,
        ]);
    }

    public function preauthorized(): self
    {
        return $this->state(function (array $attributes) {
            $recommended = bcmul($attributes['requested_amount'], '0.8', 4);
            return [
                'status' => EstadoSolicitudIncremento::PREAUTHORIZED,
                'recommended_amount' => $recommended,
            ];
        });
    }

    public function rejectedByCoordinator(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => EstadoSolicitudIncremento::REJECTED_BY_COORDINATOR,
        ]);
    }

    public function rejectedByManager(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => EstadoSolicitudIncremento::REJECTED_BY_MANAGER,
            'manager_decided_by' => User::factory(),
            'manager_decided_at' => now(),
        ])->preauthorized();
    }

    public function authorizedPartial(): self
    {
        return $this->state(function (array $attributes) {
            $recommended = bcmul($attributes['requested_amount'], '0.8', 4);
            $authorized = bcmul($recommended, '0.9', 4);
            return [
                'status' => EstadoSolicitudIncremento::AUTHORIZED_PARTIAL,
                'recommended_amount' => $recommended,
                'authorized_amount' => $authorized,
                'manager_decided_by' => User::factory(),
                'manager_decided_at' => now(),
            ];
        });
    }

    public function authorizedTotal(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => EstadoSolicitudIncremento::AUTHORIZED_TOTAL,
                'recommended_amount' => $attributes['requested_amount'],
                'authorized_amount' => $attributes['requested_amount'],
                'manager_decided_by' => User::factory(),
                'manager_decided_at' => now(),
            ];
        });
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => EstadoSolicitudIncremento::COMPLETED,
        ])->authorizedTotal();
    }
}
