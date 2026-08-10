<?php

namespace App\Http\Requests\Api\V1\Credito;

use Illuminate\Foundation\Http\FormRequest;

class ListarSolicitudesIncrementoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Se maneja en el controlador/policy
    }

    public function rules(): array
    {
        return [
            'request_number' => ['nullable', 'string', 'max:255'],
            'distributor_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'coordinator_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'max:255'],
            'requested_from' => ['nullable', 'date'],
            'requested_to' => ['nullable', 'date'],
            'manager_decided_from' => ['nullable', 'date'],
            'manager_decided_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:request_number,-request_number,requested_amount,-requested_amount,requested_at,-requested_at,manager_decided_at,-manager_decided_at,status,-status'],
        ];
    }
}
