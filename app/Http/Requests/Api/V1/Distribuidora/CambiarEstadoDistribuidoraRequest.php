<?php

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

class CambiarEstadoDistribuidoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
