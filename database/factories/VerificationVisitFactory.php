<?php

namespace Database\Factories;

use App\Enums\VerificationVisitStatus;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VerificationVisitFactory extends Factory
{
    protected $model = VerificationVisit::class;

    public function configure(): static
    {
        return $this->afterMaking(function (VerificationVisit $visit): void {
            $status = $visit->status instanceof VerificationVisitStatus
                ? $visit->status->value
                : $visit->status;

            if ($status === VerificationVisitStatus::COMPLETED->value) {
                $visit->completed_at ??= now();
            }
        });
    }

    public function definition()
    {
        return [
            'id' => Str::uuid(),
            'application_id' => DistributorApplication::factory(),
            'verifier_id' => User::factory(),
            'assigned_by' => User::factory(),
            'status' => VerificationVisitStatus::ASSIGNED,
            'assigned_at' => now(),
            'scheduled_for' => now(),
            'lock_version' => 1,
        ];
    }
}
