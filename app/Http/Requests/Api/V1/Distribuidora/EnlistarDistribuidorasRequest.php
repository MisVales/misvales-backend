<?php

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnlistarDistribuidorasRequest extends FormRequest
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
            'coordinator_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['PENDING_ACTIVATION', 'ACTIVE', 'DISABLED'])],
            'activation_status' => ['nullable', Rule::in(['INVITED', 'PENDING_ACTIVATION', 'ACTIVE', 'BLOCKED', 'DISABLED'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['distributor_number', 'created_at', 'activated_at', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
