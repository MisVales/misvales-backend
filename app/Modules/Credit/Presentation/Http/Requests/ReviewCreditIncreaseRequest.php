<?php

declare(strict_types=1);

namespace App\Modules\Credit\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewCreditIncreaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['PREAUTHORIZE', 'REJECT'])],
            'recommended_amount' => ['required_if:decision,PREAUTHORIZE', 'nullable', 'string', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'lock_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
