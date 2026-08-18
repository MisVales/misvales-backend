<?php

namespace Database\Factories;

use App\Enums\ApplicationCorrectionSection;
use App\Models\ApplicationCorrection;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApplicationCorrectionFactory extends Factory
{
    protected $model = ApplicationCorrection::class;

    public function definition()
    {
        return [
            'id' => Str::uuid(),
            'application_id' => DistributorApplication::factory(),
            'verification_visit_id' => VerificationVisit::factory(),
            'section' => ApplicationCorrectionSection::PERSONAL_INFO,
            'field_path' => 'first_name',
            'previous_value_payload' => json_encode(['value' => 'OldName']),
            'new_value_payload' => json_encode(['value' => 'NewName']),
            'reason' => 'Typo',
            'corrected_by' => User::factory(),
            'corrected_at' => now(),
        ];
    }
}
