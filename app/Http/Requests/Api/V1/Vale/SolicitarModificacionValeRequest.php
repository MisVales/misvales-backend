<?php

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SolicitarModificacionValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'distinct', Rule::in(['curp', 'address'])],
            'changes' => ['required', 'array', 'min:1'],
            'changes.curp' => ['sometimes', 'string', 'size:18', 'regex:/^[A-Z\d]{18}$/i'],
            'changes.address' => ['sometimes', 'array'],
            'changes.address.street' => ['required_with:changes.address', 'string', 'max:100'],
            'changes.address.exterior_number' => ['required_with:changes.address', 'string', 'max:20'],
            'changes.address.interior_number' => ['nullable', 'string', 'max:20'],
            'changes.address.neighborhood' => ['required_with:changes.address', 'string', 'max:100'],
            'changes.address.postal_code' => ['required_with:changes.address', 'digits:5'],
            'changes.address.municipality' => ['required_with:changes.address', 'string', 'max:100'],
            'changes.address.city' => ['required_with:changes.address', 'string', 'max:100'],
            'changes.address.state' => ['required_with:changes.address', 'string', 'max:50'],
            'changes.address.country' => ['nullable', 'string', 'size:2'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = collect($this->input('fields', []))->sort()->values()->all();
            $changes = collect(array_keys($this->input('changes', [])))->sort()->values()->all();

            if ($fields !== $changes) {
                $validator->errors()->add('changes', 'Captura exactamente los campos seleccionados para corrección.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'changes.curp.size' => 'La CURP debe tener exactamente 18 caracteres.',
            'changes.curp.regex' => 'La CURP debe contener exactamente 18 letras o números.',
            'changes.address.postal_code.digits' => 'El código postal debe tener exactamente 5 dígitos numéricos.',
        ];
    }
}
