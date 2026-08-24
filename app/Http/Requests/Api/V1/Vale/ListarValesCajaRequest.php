<?php

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListarValesCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('vouchers.cash_branch') ?? false;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(['pending', 'history'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
