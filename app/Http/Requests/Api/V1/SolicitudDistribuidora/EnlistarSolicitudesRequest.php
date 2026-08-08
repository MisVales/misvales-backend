<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use App\Enums\EstadoSolicitudDistribuidora;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EnlistarSolicitudesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'application_number' => ['sometimes', 'string', 'max:32'],
            'status' => ['sometimes', Rule::enum(EstadoSolicitudDistribuidora::class)],
            'branch_id' => ['sometimes', 'uuid'],
            'coordinator_id' => ['sometimes', 'uuid'],
            'created_from' => ['sometimes', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'submitted_from' => ['sometimes', 'date_format:Y-m-d'],
            'submitted_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:submitted_from'],
            'sort' => ['sometimes', Rule::in(['application_number', 'status', 'created_at', 'updated_at', 'submitted_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }
}
