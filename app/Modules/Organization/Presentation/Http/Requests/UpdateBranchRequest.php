<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

final class UpdateBranchRequest extends OrganizationFormRequest
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
            'code' => ['required', 'string', 'max:20', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/'],
            'name' => ['required', 'string', 'max:150'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
