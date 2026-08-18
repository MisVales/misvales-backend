<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use Illuminate\Foundation\Http\FormRequest;

class AsignarVerificadorRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'verifier_id' => 'required|uuid|exists:users,id',
            'lock_version' => 'required|integer|min:1',
        ];
    }
}
