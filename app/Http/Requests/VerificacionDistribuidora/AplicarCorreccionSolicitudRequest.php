<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use App\Exceptions\BusinessException;
use App\Services\VerificacionDistribuidora\ValidadorValorVerificacion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AplicarCorreccionSolicitudRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'section' => ['required', Rule::enum(ApplicationCorrectionSection::class)],
            'field_path' => 'required|string|max:100',
            'new_value' => 'sometimes|nullable',
            'reason' => 'sometimes|nullable|string|max:255',
            'lock_version' => 'required|integer|min:1',
            'visit_id' => 'required|uuid',
            'record_id' => 'nullable|uuid',
            'difference_index' => 'required|integer|min:0|max:99',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('field_path'))) {
            $this->merge(['field_path' => $this->input('field_path') === 'curp_masked' ? 'curp' : $this->input('field_path')]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $field = (string) $this->input('field_path', '');
            $message = ValidadorValorVerificacion::mensaje($field, $this->input('new_value'));
            if ($message !== null) {
                $validator->errors()->add('new_value', $message);
            }
        }];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = $validator->errors();
        if ($errors->has('field_path') || $errors->has('new_value')) {
            throw new BusinessException('APPLICATION_CORRECTION_FIELD_INVALID', 'Campo inválido.', 422);
        }
        parent::failedValidation($validator);
    }
}
