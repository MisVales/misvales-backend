<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Requests;

use Illuminate\Validation\Rule;

final class MobilityDecisionRequest extends MobilityWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['sometimes', Rule::in(['AUTHORIZE', 'REJECT'])],
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reauthentication_token' => ['sometimes', 'string', 'max:512'],
            ...$this->idempotencyRules(),
        ];
    }
}
