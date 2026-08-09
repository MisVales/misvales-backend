<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Branch;
use App\Models\DistributorApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DistributorApplicationFactory extends Factory
{
    protected $model = DistributorApplication::class;

    public function configure(): static
    {
        return $this->afterMaking(function (DistributorApplication $application): void {
            if ($application->branch_id === null || Branch::query()->whereKey($application->branch_id)->exists()) {
                return;
            }

            $creator = User::factory()->create(['state' => 'ACTIVE']);
            $branch = new Branch;
            $branch->forceFill([
                'id' => $application->branch_id,
                'code' => strtoupper($this->faker->unique()->bothify('BR-####')),
                'name' => $this->faker->city(),
                'is_headquarters' => false,
                'status' => 'ACTIVE',
                'lock_version' => 0,
                'created_by' => $creator->id,
            ])->save();
        });
    }

    public function definition()
    {
        return [
            'id' => Str::uuid(),
            'branch_id' => function (): string {
                $creador = User::factory()->create(['state' => 'ACTIVE']);

                return Branch::create([
                    'code' => strtoupper($this->faker->unique()->bothify('BR-####')),
                    'name' => $this->faker->city(),
                    'is_headquarters' => false,
                    'status' => 'ACTIVE',
                    'created_by' => $creador->id,
                ])->id;
            },
            'applicant_data' => [
                'personal_info' => [
                    'first_name' => $this->faker->firstName,
                    'last_name' => $this->faker->lastName,
                    'curp' => strtoupper(Str::random(18)),
                    'rfc' => strtoupper(Str::random(13)),
                ],
            ],
            'original_applicant_data' => fn (array $attributes) => $attributes['applicant_data'],
            'submitted_by' => User::factory(),
            'status' => ApplicationStatus::DRAFT,
            'lock_version' => 1,
        ];
    }
}
