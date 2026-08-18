<?php

namespace App\Http\Requests\Api\V1\Cliente;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActualizarMovimientoCarteraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'informational_status' => ['sometimes', 'nullable', Rule::in(['PENDING', 'PARTIALLY_PAID', 'PAID'])],
            'occurred_at' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'last_payment_at' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'amount' => ['prohibited'],
            'entry_type' => ['prohibited'],
            'client_id' => ['prohibited'],
            'distributor_id' => ['prohibited'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('CLIENT_PORTFOLIO_ENTRY_IMMUTABLE', 'El movimiento contiene campos no editables o invÃ¡lidos.', 422, $validator->errors()->toArray(), []);
    }
}
