<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Requests;

final class CreateCoordinatorBatchRequest extends MobilityWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outgoing_coordinator_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
            'reauthentication_token' => ['required', 'string', 'max:512'],
            ...$this->idempotencyRules(),
        ];
    }
}
