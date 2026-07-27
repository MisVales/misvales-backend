<?php

declare(strict_types=1);

namespace App\Modules\Relation\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartCutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorize via policies/MFA middleware
    }

    public function rules(): array
    {
        return [
            'operative_date' => 'required|date|date_format:Y-m-d',
            'mfa_token' => 'required|string',
        ];
    }
}
