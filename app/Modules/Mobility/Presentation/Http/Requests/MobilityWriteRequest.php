<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class MobilityWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    protected function idempotencyRules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'max:150']];
    }
}
