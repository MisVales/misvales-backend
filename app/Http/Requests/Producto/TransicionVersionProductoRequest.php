<?php

namespace App\Http\Requests\Producto;

use Illuminate\Foundation\Http\FormRequest;

class TransicionVersionProductoRequest extends FormRequest
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
