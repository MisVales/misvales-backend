<?php

namespace App\Http\Requests\Api\V1\Conciliacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecidirConciliacionManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->hasPermissionTo('manual_reconciliation.authorize_global') ?? false)
            || ($this->user()?->hasPermissionTo('manual_reconciliation.authorize_branch') ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['AUTHORIZE', 'REJECT'])],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:decision,REJECT'],
        ];
    }
}
