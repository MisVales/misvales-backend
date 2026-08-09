<?php

namespace App\Http\Requests\PeriodoCanje;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarPeriodoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'starts_at' => ['sometimes', 'required', 'date', 'after_or_equal:today'],
            'ends_at' => ['sometimes', 'required', 'date', 'after:starts_at'], // Punto 69
            'point_value' => ['sometimes', 'required', 'numeric', 'min:0.0001'],
            'reason' => ['required', 'string'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
