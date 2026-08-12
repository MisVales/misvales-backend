<?php

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;

final class FeriarValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['bank_transaction_number' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9\-_.]+$/'], 'lock_version' => ['required', 'integer', 'min:1']];
    }
}
