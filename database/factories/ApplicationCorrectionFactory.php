<?php
namespace Database\Factories;
use App\Models\ApplicationCorrection;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Enums\ApplicationCorrectionSection;

class ApplicationCorrectionFactory extends Factory {
    protected $model = ApplicationCorrection::class;
    public function definition() {
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
