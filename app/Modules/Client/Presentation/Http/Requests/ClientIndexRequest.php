<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Valida filtros y ordenamiento mediante listas cerradas. */
final class ClientIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'curp' => ['sometimes', 'string', 'max:30'],
            'distributor_id' => ['sometimes', 'uuid'],
            'branch_id' => ['sometimes', 'uuid'],
            'registered_from' => ['sometimes', 'date_format:Y-m-d'],
            'registered_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:registered_from'],
            'portfolio_tracking_enabled' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['name', 'registered_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
