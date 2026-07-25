<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Requests;

use App\Modules\Client\Domain\Portfolio\PortfolioStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Limita el PATCH a nota y estado informativo con versión esperada. */
final class UpdatePortfolioEntryRequest extends FormRequest
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
            'informational_status' => ['sometimes', Rule::enum(PortfolioStatus::class), 'required_without:note'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000', 'required_without:informational_status'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'amount' => ['prohibited'],
            'client_id' => ['prohibited'],
            'distributor_id' => ['prohibited'],
            'assignment_id' => ['prohibited'],
            'voucher_id' => ['prohibited'],
            'entry_type' => ['prohibited'],
            'occurred_on' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }
}
