<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class EnviarSolicitudRevisionRequest extends FormRequest
{
    use RechazaPropiedadesDesconocidas;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:1']];
    }
}
