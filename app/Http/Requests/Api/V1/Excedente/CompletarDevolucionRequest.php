<?php

namespace App\Http\Requests\Api\V1\Excedente;

use Illuminate\Foundation\Http\FormRequest;

final class CompletarDevolucionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'executed_at' => ['required', 'date', 'before_or_equal:now'],
            'method' => ['required', 'string', 'max:50'],
            'reference' => ['required', 'string', 'max:100'],
            'evidence_media_id' => ['required', 'uuid', 'exists:media_files,id'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
