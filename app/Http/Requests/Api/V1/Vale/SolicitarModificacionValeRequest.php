<?php

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SolicitarModificacionValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['fields' => ['required', 'array', 'min:1'], 'fields.*' => ['required', 'distinct', Rule::in(['curp', 'address'])], 'reason' => ['required', 'string', 'max:500']];
    }
}
