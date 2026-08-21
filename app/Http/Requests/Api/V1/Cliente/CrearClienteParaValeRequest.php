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
            'first_name' => ['required', 'string', 'max:120'],
            'first_last_name' => ['required', 'string', 'max:120'],
            'second_last_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
