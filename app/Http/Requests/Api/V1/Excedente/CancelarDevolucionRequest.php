<?php

namespace App\Http\Requests\Api\V1\Excedente;

use Illuminate\Foundation\Http\FormRequest;

final class CancelarDevolucionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
