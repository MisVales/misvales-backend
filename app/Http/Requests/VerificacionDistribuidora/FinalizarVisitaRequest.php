<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Enums\VerificationVisitResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinalizarVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resultado_fisico' => ['required', Rule::enum(VerificationVisitResult::class)],
            'observaciones' => 'nullable|string|max:2000|required_if:resultado_fisico,UNFAVORABLE',
            'lock_version' => 'required|integer|min:1',
        ];
    }
}
