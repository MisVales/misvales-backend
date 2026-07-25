<?php

declare(strict_types=1);

namespace App\Modules\Credit\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ManagerCreditIncreaseDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['AUTHORIZE', 'REJECT'])],
            'authorized_amount' => ['required_if:decision,AUTHORIZE', 'nullable', 'string', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'reauthentication_id' => ['required_without:reauth_token', 'nullable', 'string', 'min:32', 'max:512'],
            'reauth_token' => ['required_without:reauthentication_id', 'nullable', 'string', 'min:32', 'max:512'],
            'lock_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function reauthenticationToken(): string
    {
        return $this->string('reauthentication_id', $this->string('reauth_token')->toString())->toString();
    }
}
