<?php

namespace App\Http\Requests\Api\V1\Conciliacion;

use Illuminate\Foundation\Http\FormRequest;

final class CrearAclaracionPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('payment_clarifications.create_own') ?? false;
    }

    public function rules(): array
    {
        return [
            'evidence_media_id' => ['required', 'uuid', 'exists:media_files,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
