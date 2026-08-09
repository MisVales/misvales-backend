<?php

namespace Database\Factories;

use App\Models\AsignacionClienteDistribuidora;
use App\Models\Cliente;
use App\Models\Distribuidora;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AsignacionClienteDistribuidora> */
class AsignacionClienteDistribuidoraFactory extends Factory
{
    protected $model = AsignacionClienteDistribuidora::class;

    public function definition(): array
    {
        return [
            'client_id' => Cliente::factory(),
            'distributor_id' => Distribuidora::factory(),
            'branch_id' => fn (array $atributos): string => Distribuidora::query()->findOrFail($atributos['distributor_id'])->branch_id,
            'starts_at' => now(),
            'assigned_by' => User::factory(),
            'reason' => 'Asignación sintética de prueba',
        ];
    }
}
