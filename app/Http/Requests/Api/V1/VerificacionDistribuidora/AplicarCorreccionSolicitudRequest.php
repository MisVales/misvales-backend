<?php

namespace App\Http\Requests\Api\V1\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AplicarCorreccionSolicitudRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'visit_id' => ['required', 'uuid'],
            'section' => ['required', Rule::in(array_map(
                static fn (ApplicationCorrectionSection $section): string => $section->value,
                ApplicationCorrectionSection::cases(),
            ))],
            'field_path' => ['required', 'string', 'max:100'],
            'new_value' => ['nullable'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'record_id' => ['nullable', 'uuid'],
            'difference_index' => ['required', 'integer', 'min:0'],
        ];
    }
}
