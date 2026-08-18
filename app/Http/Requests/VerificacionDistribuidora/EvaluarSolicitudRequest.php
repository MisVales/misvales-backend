<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Enums\ApplicationEvaluationResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluarSolicitudRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'visit_id' => ['required', 'uuid'],
            'result' => ['required', 'string', Rule::enum(ApplicationEvaluationResult::class)],
            'reason' => 'required|string|max:2000',
            'payload' => 'nullable|array',
            'lock_version' => 'required|integer|min:1',
        ];
    }
}
