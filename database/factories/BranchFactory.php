<?php

namespace Database\Factories;

use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    /** @var class-string<Branch> */
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => 'Sucursal '.fake()->unique()->city(),
            'is_headquarters' => false,
            'is_active' => true,
        ];
    }
}
