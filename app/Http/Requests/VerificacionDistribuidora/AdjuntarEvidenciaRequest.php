<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Exceptions\BusinessException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class AdjuntarEvidenciaRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'file' => 'required|file|mimes:jpeg,png,pdf|max:10240',
            'file_type' => 'required|string|max:50',
            'lock_version' => 'required|integer|min:1',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = $validator->errors();
        if ($errors->has('file')) {
            $failed = $validator->failed()['file'];
            if (isset($failed['Max'])) {
                throw new BusinessException('VERIFICATION_EVIDENCE_TOO_LARGE', 'Archivo excede límite.', 422);
            }
            if (isset($failed['Mimes'])) {
                throw new BusinessException('VERIFICATION_EVIDENCE_MIME_INVALID', 'Mime type inválido.', 422);
            }
            throw new BusinessException('VERIFICATION_EVIDENCE_TYPE_INVALID', 'Archivo inválido.', 422);
        }
        parent::failedValidation($validator);
    }
}
