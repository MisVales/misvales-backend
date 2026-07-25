<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

use App\Modules\DistributorOnboarding\Domain\Decisions\CoordinatorDecision;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class CoordinatorDecisionRequest extends OnboardingRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'decision' => ['required', Rule::enum(CoordinatorDecision::class)],
            'reason' => ['required', 'string', 'max:4000'],
        ];
    }
}
