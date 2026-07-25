<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Exige la identidad de operación y autorización M09 para una nueva versión bancaria. */
final class StoreBankAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('lock_version') && $this->header('If-Match') !== null) {
            $this->merge(['lock_version' => trim((string) $this->header('If-Match'), '"')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'authorization_id' => ['required', 'uuid'],
            'operation_id' => ['required', 'uuid'],
            'bank_account' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:1000'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'client_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'effective_from' => ['prohibited'],
            'effective_to' => ['prohibited'],
        ];
    }
}
