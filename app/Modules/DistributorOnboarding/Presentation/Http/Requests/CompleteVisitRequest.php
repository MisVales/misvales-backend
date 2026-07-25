<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

use App\Modules\DistributorOnboarding\Domain\Verification\VisitResult;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class CompleteVisitRequest extends OnboardingRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'result' => ['required', Rule::enum(VisitResult::class)],
            'observations' => ['required', 'string', 'max:4000'],
        ];
    }
}
