<?php

namespace App\Http\Requests\Api\V1\Cliente;

use App\Models\Cliente;
use Illuminate\Foundation\Http\FormRequest;

final class CrearClienteParaValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Cliente::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'registration_draft_id' => ['required', 'uuid', 'exists:client_registration_drafts,id'],
        ];
    }
}
