<?php
namespace App\Http\Requests\VerificacionDistribuidora;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use App\Exceptions\BusinessException;
use App\Enums\ApplicationCorrectionSection;
use Illuminate\Validation\Rule;

class AplicarCorreccionSolicitudRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            'section' => ['required', Rule::enum(ApplicationCorrectionSection::class)],
            'field_path' => 'required|string|max:100',
            'new_value' => 'required',
            'reason' => 'required|string|max:255',
            'lock_version' => 'required|integer|min:1',
            'visit_id' => 'required|uuid'
        ];
    }

    protected function failedValidation(Validator $validator) {
        $errors = $validator->errors();
        if ($errors->has('field_path') || $errors->has('new_value')) {
            throw new BusinessException('APPLICATION_CORRECTION_FIELD_INVALID', 'Campo inválido.', 422);
        }
        parent::failedValidation($validator);
    }
}
