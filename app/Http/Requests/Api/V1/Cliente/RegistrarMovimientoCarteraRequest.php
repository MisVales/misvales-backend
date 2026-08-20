<?php

namespace App\Http\Requests\Api\V1\Cliente;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarMovimientoCarteraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_type' => ['required', Rule::in(['DEBT', 'PAYMENT', 'PARTIAL_PAYMENT', 'STATUS_UPDATE', 'NOTE', 'ADJUSTMENT_INCREASE', 'ADJUSTMENT_DECREASE'])],
            'amount' => [
                Rule::requiredIf(fn (): bool => ! in_array($this->input('entry_type'), ['NOTE', 'STATUS_UPDATE'], true)),
                'nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/', 'not_regex:/^0+(\.0{1,4})?$/',
            ],
            'informational_status' => ['nullable', Rule::in(['PENDING', 'PARTIALLY_PAID', 'PAID'])],
            'occurred_at' => ['required', 'date'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'last_payment_at' => ['nullable', 'date'],
            'note' => [Rule::requiredIf(fn (): bool => in_array($this->input('entry_type'), ['ADJUSTMENT_INCREASE', 'ADJUSTMENT_DECREASE'], true)), 'nullable', 'string', 'max:2000'],
            'related_voucher_id' => ['nullable', 'uuid'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('CLIENT_PORTFOLIO_ENTRY_INVALID', 'El movimiento de cartera no es válido.', 422, $validator->errors()->toArray(), []);
    }
}
