<?php

namespace App\Http\Requests\PeriodoCanje;

use Illuminate\Foundation\Http\FormRequest;

class TransicionPeriodoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
