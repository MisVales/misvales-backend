<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

final class StoreApplicationRequest extends OnboardingRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(false),
            'contact_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'account_name' => ['required', 'string', 'max:255'],
        ];
    }
}
