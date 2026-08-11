<?php

namespace App\Http\Requests\PeriodoCanje;

use Illuminate\Foundation\Http\FormRequest;

class CrearPeriodoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', 'unique:redemption_periods,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'ends_at' => ['required', 'date', 'after:starts_at'], // Punto 69
            'reason' => ['required', 'string'],
        ];
    }
}
