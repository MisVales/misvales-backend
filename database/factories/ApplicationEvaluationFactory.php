<?php
namespace Database\Factories;
use App\Models\ApplicationEvaluation;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Enums\ApplicationEvaluationResult;

class ApplicationEvaluationFactory extends Factory {
    protected $model = ApplicationEvaluation::class;
    public function definition() {
        return [
            'id' => Str::uuid(),
            'application_id' => DistributorApplication::factory(),
            'verification_visit_id' => fn (array $attributes) => VerificationVisit::factory()->create([
                'application_id' => $attributes['application_id'],
                'status' => \App\Enums\VerificationVisitStatus::COMPLETED,
                'result' => \App\Enums\VerificationVisitResult::FAVORABLE,
                'completed_at' => now(),
            ])->id,
            'result' => ApplicationEvaluationResult::COMPLIES,
            'reason' => 'All good',
            'evaluated_by' => User::factory(),
            'evaluated_at' => now(),
        ];
    }
}
