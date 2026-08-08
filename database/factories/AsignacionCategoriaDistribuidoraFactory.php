<?php

namespace Database\Factories;

use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\Distribuidora;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AsignacionCategoriaDistribuidora> */
class AsignacionCategoriaDistribuidoraFactory extends Factory
{
    protected $model = AsignacionCategoriaDistribuidora::class;

    public function definition(): array
    {
        return [
            'distributor_id' => Distribuidora::factory(),
            'category_version_id' => function (): string {
                $creador = User::factory()->create(['state' => 'ACTIVE']);
                $categoria = Category::create([
                    'code' => strtoupper(fake()->unique()->bothify('CAT-####')),
                    'status' => 'ACTIVE',
                    'created_by' => $creador->id,
                ]);

                return CategoryVersion::create([
                    'category_id' => $categoria->id,
                    'version' => 1,
                    'name' => fake()->words(2, true),
                    'profit_percentage' => '0.050000',
                    'status' => 'PUBLISHED',
                    'effective_from' => now()->subDay(),
                    'reason' => 'Datos sintéticos de prueba',
                    'created_by' => $creador->id,
                    'published_by' => $creador->id,
                    'published_at' => now()->subDay(),
                ])->id;
            },
            'starts_at' => now(),
            'ends_at' => null,
            'assigned_by' => User::factory(),
            'reason' => fake()->sentence(),
        ];
    }
}
