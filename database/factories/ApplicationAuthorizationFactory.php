<?php

namespace Database\Factories;

use App\Enums\ApplicationAuthorizationDecision;
use App\Models\ApplicationAuthorization;
use App\Models\DistributorApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApplicationAuthorizationFactory extends Factory
{
    protected $model = ApplicationAuthorization::class;

    public function definition()
    {
        return [
            'id' => Str::uuid(),
            'application_id' => DistributorApplication::factory(),
            'decision' => ApplicationAuthorizationDecision::APPROVED,
            'reason' => 'Approved by manager',
            'initial_credit_line_amount' => 15000.00,
            'authorized_by' => User::factory(),
            'authorized_at' => now(),
        ];
    }
}
