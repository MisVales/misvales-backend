<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use Illuminate\Foundation\Http\FormRequest;

class FinalizarCorreccionesRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'lock_version' => 'required|integer|min:1',
            'force' => 'nullable|boolean',
        ];
    }
}
