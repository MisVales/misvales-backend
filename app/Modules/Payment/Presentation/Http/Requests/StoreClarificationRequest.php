<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Valida datos declarados y evidencia de una aclaración propia. */
final class StoreClarificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'relation_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'reported_amount' => ['required', 'decimal:0,4', 'gt:0'],
            'reported_date' => ['required', 'date_format:Y-m-d'],
            'reported_reference' => ['required', 'string', 'max:255'],
            'reported_bank_folio' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence' => ['required', 'file'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:200'],
        ];
    }
}
