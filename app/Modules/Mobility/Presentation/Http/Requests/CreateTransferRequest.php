<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Requests;

final class CreateTransferRequest extends MobilityWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'recipient_distributor_id' => ['required', 'uuid'],
            'client_version' => ['required', 'integer', 'min:1'],
            'portfolio_version' => ['required', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ...$this->idempotencyRules(),
        ];
    }
}
