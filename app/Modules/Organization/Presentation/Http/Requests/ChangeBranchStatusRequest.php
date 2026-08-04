<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

final class ChangeBranchStatusRequest extends OrganizationFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('lock_version') || ! $this->hasHeader('If-Match')) {
            return;
        }

        $value = preg_replace('/\AW\/|["\s]/', '', (string) $this->header('If-Match'));

        if (is_string($value) && ctype_digit($value)) {
            $this->merge(['lock_version' => (int) $value]);
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
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
