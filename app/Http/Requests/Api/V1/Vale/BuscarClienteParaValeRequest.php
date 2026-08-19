<?php

namespace App\Http\Requests\Api\V1\Vale;

use App\Models\Vale;
use Illuminate\Foundation\Http\FormRequest;

final class BuscarClienteParaValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Vale::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
