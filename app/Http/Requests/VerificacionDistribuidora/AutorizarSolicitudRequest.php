<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutorizarSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['AUTORIZADA', 'RECHAZADA'])],
            'motivo' => 'required|string|max:2000',
            'lock_version' => 'required|integer|min:1',
        ];
    }
}
