<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Valida la envoltura HTTP de una importación antes del contrato bancario. */
final class ReceiveBankImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            'idempotency_key' => $this->header('Idempotency-Key'),
        ];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:200'],
        ];
    }
}
