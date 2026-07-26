<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Presentation\Http\Requests;

final class DecideRefundRequest extends ExcessMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->baseRules(),
            'reauthentication_token' => ['required', 'string', 'min:32', 'max:512'],
            'reason' => [
                $this->routeIs('api.v1.refund-requests.reject') ? 'required' : 'nullable',
                'string',
                'min:3',
                'max:1000',
            ],
        ];
    }
}
