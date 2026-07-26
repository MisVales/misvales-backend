<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Requests;

final class BranchClientDestinationsRequest extends MobilityWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.client_id' => ['required', 'uuid', 'distinct'],
            'items.*.destination_distributor_id' => ['required', 'uuid'],
            ...$this->idempotencyRules(),
        ];
    }
}
