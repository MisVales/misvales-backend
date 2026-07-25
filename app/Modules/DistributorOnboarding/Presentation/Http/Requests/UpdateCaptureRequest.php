<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

final class UpdateCaptureRequest extends OnboardingRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'contact_email' => ['required_without_all:account_name,personal_data', 'string', 'email:rfc', 'max:254'],
            'account_name' => ['required_without_all:contact_email,personal_data', 'string', 'max:255'],
            'personal_data' => ['required_without_all:contact_email,account_name', 'array', 'min:1'],
            'personal_data.first_name' => ['sometimes', 'string', 'max:150'],
            'personal_data.paternal_surname' => ['sometimes', 'string', 'max:150'],
            'personal_data.maternal_surname' => ['sometimes', 'nullable', 'string', 'max:150'],
            'personal_data.curp' => ['sometimes', 'nullable', 'string', 'max:18'],
            'personal_data.rfc' => ['sometimes', 'nullable', 'string', 'max:13'],
            'personal_data.birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'personal_data.birth_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'personal_data.birth_state' => ['sometimes', 'nullable', 'string', 'max:150'],
            'personal_data.birth_city' => ['sometimes', 'nullable', 'string', 'max:150'],
            'personal_data.declared_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'personal_data.official_identification_media_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
