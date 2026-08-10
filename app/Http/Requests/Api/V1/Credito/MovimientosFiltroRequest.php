<?php

namespace App\Http\Requests\Api\V1\Credito;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MovimientosFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorización se hará en el Controller mediante scope
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', 'max:50'],
            'occurred_from' => ['sometimes', 'date', 'before_or_equal:today'],
            'occurred_to' => ['sometimes', 'date', 'after_or_equal:occurred_from', 'before_or_equal:today'],
            'sort' => ['sometimes', 'string', Rule::in([
                'sequence', '-sequence',
                'occurred_at', '-occurred_at',
                'amount', '-amount'
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
