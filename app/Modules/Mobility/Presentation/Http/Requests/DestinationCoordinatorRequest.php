<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Requests;

final class DestinationCoordinatorRequest extends MobilityWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'destination_coordinator_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            ...$this->idempotencyRules(),
        ];
    }
}
