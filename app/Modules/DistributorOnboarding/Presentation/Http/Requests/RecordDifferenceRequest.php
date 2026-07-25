<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class RecordDifferenceRequest extends OnboardingRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'section' => ['required', Rule::enum(ExpedientSection::class)],
            'field_path' => ['required', 'string', 'max:255'],
            'declared_value' => ['required', 'string', 'max:8000'],
            'observed_value' => ['required', 'string', 'max:8000'],
            'description' => ['required', 'string', 'max:4000'],
            'classification_code' => ['required', 'string', 'max:80'],
            'evidence_media_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
