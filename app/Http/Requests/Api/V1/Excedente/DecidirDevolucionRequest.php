<?php

namespace App\Http\Requests\Api\V1\Excedente;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecidirDevolucionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['AUTHORIZE', 'REJECT'])], 'reason' => ['required', 'string', 'max:1000']];
    }
}
