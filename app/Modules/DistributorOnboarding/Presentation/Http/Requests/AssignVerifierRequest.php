<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

final class AssignVerifierRequest extends OnboardingRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'verifier_id' => ['required', 'uuid'],
        ];
    }
}
