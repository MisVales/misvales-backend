<?php

namespace App\Http\Requests\Api\V1\Cliente;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnlistarClientesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'branch_id' => ['nullable', 'uuid'],
            'distributor_id' => ['nullable', 'uuid'],
            'portfolio_status' => ['nullable', Rule::in(['PENDING', 'PARTIALLY_PAID', 'PAID'])],
            'has_portfolio_balance' => ['nullable', 'boolean'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['client_number', 'first_name', 'first_last_name', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('CLIENT_VALIDATION_FAILED', 'Los filtros enviados no son vÃ¡lidos.', 422, $validator->errors()->toArray(), []);
    }
}
