<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class RegistrarDiferenciasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'differences_payload' => ['required', 'array:has_differences,items'],
            'differences_payload.has_differences' => ['required', 'boolean'],
            'differences_payload.items' => ['present', 'array', 'max:100'],
            'differences_payload.items.*' => ['array:section,field,declared_value,observed_value,description,record_id,record_label'],
            'differences_payload.items.*.section' => ['required', Rule::enum(ApplicationCorrectionSection::class)],
            'differences_payload.items.*.field' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'differences_payload.items.*.declared_value' => ['nullable', 'string', 'max:4000'],
            'differences_payload.items.*.observed_value' => ['nullable', 'string', 'max:4000'],
            'differences_payload.items.*.description' => ['required', 'string', 'max:2000'],
            'differences_payload.items.*.record_id' => ['nullable', 'uuid'],
            'differences_payload.items.*.record_label' => ['nullable', 'string', 'max:255'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $payload = $this->input('differences_payload', []);
            if ((bool) ($payload['has_differences'] ?? false) !== (($payload['items'] ?? []) !== [])) {
                $validator->errors()->add('differences_payload', 'La bandera de diferencias debe coincidir con los elementos capturados.');
            }
        }];
    }
}
