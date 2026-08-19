<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Exceptions\BusinessException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutorizarSolicitudRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('decision') === 'APPROVED' && (! $this->filled('reason') || trim((string) $this->input('reason')) === '')) {
            $this->merge(['reason' => 'Aprobación gerencial']);
        }
    }

    public function rules()
    {
        return [
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'initial_credit_line_amount' => [
                'nullable',
                Rule::requiredIf($this->input('decision') === 'APPROVED'),
                Rule::prohibitedIf($this->input('decision') === 'REJECTED'),
                'numeric',
                'gt:0',
            ],
            'reason' => 'required|string|max:255',
            'lock_version' => 'required|integer|min:1',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        if ($validator->errors()->has('initial_credit_line_amount')) {
            $failed = $validator->failed()['initial_credit_line_amount'] ?? [];
            if (isset($failed['Required'])) {
                throw new BusinessException('APPLICATION_INITIAL_CREDIT_LINE_REQUIRED', 'Se requiere la línea inicial autorizada.', 422);
            }

            throw new BusinessException('APPLICATION_INITIAL_CREDIT_LINE_INVALID', 'La línea inicial autorizada debe ser mayor que cero.', 422);
        }

        parent::failedValidation($validator);
    }
}
