<?php
namespace Database\Factories;
use App\Models\VerificationVisit;
use App\Models\DistributorApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Enums\VerificationVisitStatus;

class VerificationVisitFactory extends Factory {
    protected $model = VerificationVisit::class;
    public function definition() {
        return [
            'id' => Str::uuid(),
            'application_id' => DistributorApplication::factory(),
            'verifier_id' => User::factory(),
            'assigned_by' => User::factory(),
            'status' => VerificationVisitStatus::ASSIGNED,
            'assigned_at' => now(),
            'lock_version' => 1
        ];
    }
}
