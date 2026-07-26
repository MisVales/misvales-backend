<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Requests;

final class CreateReassignmentRequest extends MobilityWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.client_id' => ['required', 'uuid', 'distinct'],
            'items.*.destination_distributor_id' => ['required', 'uuid'],
            'items.*.client_version' => ['required', 'integer', 'min:1'],
            'items.*.portfolio_version' => ['required', 'integer', 'min:1'],
            ...$this->idempotencyRules(),
        ];
    }
}
