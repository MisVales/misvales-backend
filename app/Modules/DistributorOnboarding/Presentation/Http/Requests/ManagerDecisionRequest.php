<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

use App\Modules\DistributorOnboarding\Domain\Decisions\ManagerDecision;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class ManagerDecisionRequest extends OnboardingRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'decision' => ['required', Rule::enum(ManagerDecision::class)],
            'initial_credit_line' => [
                Rule::requiredIf(fn (): bool => $this->input('decision') === ManagerDecision::APPROVE->value),
                'nullable',
                'string',
                'regex:/^(0|[1-9]\d{0,14})(\.\d{1,4})?$/',
            ],
            'reason' => ['required', 'string', 'max:4000'],
            'reauthentication_token' => [
                Rule::requiredIf(fn (): bool => $this->input('decision') === ManagerDecision::APPROVE->value),
                'nullable',
                'string',
                'max:512',
            ],
        ];
    }
}
