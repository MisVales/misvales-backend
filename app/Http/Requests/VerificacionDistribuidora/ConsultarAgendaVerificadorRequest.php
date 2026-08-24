<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use Illuminate\Foundation\Http\FormRequest;

class ConsultarAgendaVerificadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ];
    }

    public function messages(): array
    {
        return [
            'from.required' => 'Indica el inicio del periodo de agenda.',
            'to.required' => 'Indica el fin del periodo de agenda.',
            'to.after' => 'El fin del periodo debe ser posterior al inicio.',
        ];
    }
}
