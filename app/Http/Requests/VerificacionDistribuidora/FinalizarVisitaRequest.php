<?php
namespace App\Http\Requests\VerificacionDistribuidora;
use Illuminate\Foundation\Http\FormRequest;
use App\Enums\VerificationVisitResult;
use Illuminate\Validation\Rule;

class FinalizarVisitaRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            'result' => ['required', 'string', Rule::enum(VerificationVisitResult::class)],
            'observations' => 'nullable|string|max:2000',
            'differences_payload' => 'nullable|array',
            'lock_version' => 'required|integer|min:1'
        ];
    }
}
