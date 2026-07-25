<?php

declare(strict_types=1);

namespace App\Modules\Credit\Presentation\Http\Requests;

use App\Modules\Credit\Domain\Enums\IncreaseOriginType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateCreditIncreaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'requested_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,4})?$/', 'not_in:0,0.0,0.00,0.000,0.0000'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'origin' => ['sometimes', 'array'],
            'origin.type' => ['required_with:origin', Rule::enum(IncreaseOriginType::class)],
            'origin.product_amount' => ['required_if:origin.type,INSUFFICIENT_CREDIT', 'nullable', 'string', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $headerKey = $this->headers->get('Idempotency-Key');
        $this->merge([
            'idempotency_key' => is_string($headerKey) ? $headerKey : $this->input('idempotency_key'),
        ]);
    }
}
