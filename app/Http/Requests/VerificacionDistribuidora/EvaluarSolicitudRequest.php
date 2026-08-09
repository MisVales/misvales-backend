<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Enums\ApplicationEvaluationResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluarSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dictamen' => ['required', Rule::enum(ApplicationEvaluationResult::class)],
            'motivo' => 'required|string|max:2000',
            'lock_version' => 'required|integer|min:1',
        ];
    }
}
