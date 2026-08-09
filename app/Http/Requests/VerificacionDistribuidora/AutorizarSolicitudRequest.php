<?php
namespace App\Http\Requests\VerificacionDistribuidora;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use App\Exceptions\BusinessException;

class AutorizarSolicitudRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            'initial_credit_line_amount' => 'required|numeric|min:1',
            'reason' => 'required|string|max:255',
            'lock_version' => 'required|integer|min:1'
        ];
    }
    
    protected function failedValidation(Validator $validator) {
        $errors = $validator->errors();
        if ($errors->has('initial_credit_line_amount')) {
            $failed = $validator->failed()['initial_credit_line_amount'];
            if (isset($failed['Required'])) throw new BusinessException('APPLICATION_INITIAL_CREDIT_LINE_REQUIRED', 'Se requiere límite.', 422);
            throw new BusinessException('APPLICATION_INITIAL_CREDIT_LINE_INVALID', 'Límite inválido.', 422);
        }
        parent::failedValidation($validator);
    }
}
