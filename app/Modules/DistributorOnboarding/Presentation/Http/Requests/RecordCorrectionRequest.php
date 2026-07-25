<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class RecordCorrectionRequest extends OnboardingRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'section' => ['required', Rule::enum(ExpedientSection::class)],
            'field_path' => ['required', 'string', 'max:255'],
            'expected_original_value' => ['required', 'string', 'max:8000'],
            'corrected_value' => ['required', 'string', 'max:8000'],
            'reason' => ['required', 'string', 'max:4000'],
            'difference_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
