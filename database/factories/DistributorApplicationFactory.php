<?php
namespace Database\Factories;
use App\Models\DistributorApplication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Enums\ApplicationStatus;

class DistributorApplicationFactory extends Factory {
    protected $model = DistributorApplication::class;
    public function definition() {
        return [
            'id' => Str::uuid(),
            'branch_id' => Str::uuid(),
            'applicant_data' => [
                'personal_info' => [
                    'first_name' => $this->faker->firstName,
                    'last_name' => $this->faker->lastName,
                    'curp' => strtoupper(Str::random(18)),
                    'rfc' => strtoupper(Str::random(13)),
                ]
            ],
            'status' => ApplicationStatus::DRAFT,
            'lock_version' => 1
        ];
    }
}
