<?php

namespace App\Http\Requests\Api\V1\Conciliacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListarMovimientosBancariosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('bank_movements.view_global')
            || $this->user()?->hasPermissionTo('bank_movements.view_branch');
    }

    public function rules(): array
    {
        return [
            'result' => ['nullable', Rule::in(['PARTIAL_PAYMENT', 'SETTLEMENT', 'SURPLUS', 'UNRECONCILED', 'DUPLICATE'])],
            'status' => ['nullable', Rule::in(['RECONCILED', 'UNRECONCILED', 'DUPLICATE', 'MANUAL_REQUESTED', 'MANUAL_AUTHORIZED', 'MANUALLY_RECONCILED', 'ERROR'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
