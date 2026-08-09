<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Enums\MediaFileType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjuntarEvidenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimetypes:image/jpeg,image/png,application/pdf|max:10240',
            'tipo' => ['required', Rule::enum(MediaFileType::class)],
            'lock_version' => 'required|integer|min:1',
        ];
    }
}
