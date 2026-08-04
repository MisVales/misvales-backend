<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CrearSolicitudDistribuidoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid'],
            'coordinator_id' => ['required', 'uuid'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'submitted_by' => ['prohibited'],
            'submitted_at' => ['prohibited'],
            'application_number' => ['prohibited'],
            'section_declarations' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $permitidas = ['branch_id', 'coordinator_id'];
            $desconocidas = array_diff(array_keys($this->all()), $permitidas);

            foreach ($desconocidas as $campo) {
                if (! $validator->errors()->has($campo)) {
                    $validator->errors()->add($campo, 'La propiedad no está permitida.');
                }
            }
        });
    }
}
