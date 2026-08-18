<?php

namespace Database\Factories;

use App\Enums\ApplicationEvaluationResult;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Models\ApplicationEvaluation;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApplicationEvaluationFactory extends Factory
{
    protected $model = ApplicationEvaluation::class;

    public function definition()
    {
        return [
            'id' => Str::uuid(),
            'application_id' => DistributorApplication::factory(),
            'verification_visit_id' => fn (array $attributes) => VerificationVisit::factory()->create([
                'application_id' => $attributes['application_id'],
                'status' => VerificationVisitStatus::COMPLETED,
                'result' => VerificationVisitResult::FAVORABLE,
                'completed_at' => now(),
            ])->id,
            'result' => ApplicationEvaluationResult::COMPLIES,
            'reason' => 'All good',
            'evaluated_by' => User::factory(),
            'evaluated_at' => now(),
        ];
    }
}
