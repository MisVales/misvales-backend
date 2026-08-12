<?php

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;

final class AplicarModificacionValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:8'], 'lock_version' => ['required', 'integer', 'min:1'], 'changes' => ['required', 'array', 'min:1'], 'changes.curp' => ['sometimes', 'string'], 'changes.address' => ['sometimes', 'array'], 'changes.address.street' => ['required_with:changes.address', 'string'], 'changes.address.exterior_number' => ['required_with:changes.address', 'string'], 'changes.address.interior_number' => ['nullable', 'string'], 'changes.address.neighborhood' => ['required_with:changes.address', 'string'], 'changes.address.postal_code' => ['required_with:changes.address', 'digits:5'], 'changes.address.municipality' => ['required_with:changes.address', 'string'], 'changes.address.city' => ['required_with:changes.address', 'string'], 'changes.address.state' => ['required_with:changes.address', 'string'], 'changes.address.country' => ['nullable', 'string', 'size:2']];
    }
}
