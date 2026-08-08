<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ActualizarSolicitudDistribuidoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'uuid'],
            'coordinator_id' => ['sometimes', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'submitted_by' => ['prohibited'],
            'submitted_at' => ['prohibited'],
            'application_number' => ['prohibited'],
            'section_declarations' => ['sometimes', 'array:personal_data,residence,partner,children,family_references,vehicles,assets,liabilities,employment,commercial_credits'],
            'section_declarations.*' => ['string', Rule::in(['PENDING', 'COMPLETED', 'NOT_APPLICABLE'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $permitidas = ['branch_id', 'coordinator_id', 'lock_version', 'section_declarations'];

            foreach (array_diff(array_keys($this->all()), $permitidas) as $campo) {
                if (! $validator->errors()->has($campo)) {
                    $validator->errors()->add($campo, 'La propiedad no está permitida.');
                }
            }
        });
    }
}
