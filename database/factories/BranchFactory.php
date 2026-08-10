<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => fake()->unique()->numerify('BR-####'),
            'status' => 'ACTIVE',
            'created_by' => \App\Models\User::factory(),
        ];
    }
}
