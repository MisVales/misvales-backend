<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RiskDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reauthentication_token' => ['required', 'string', 'max:512'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
