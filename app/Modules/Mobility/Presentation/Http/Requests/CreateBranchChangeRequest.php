<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Requests;

final class CreateBranchChangeRequest extends MobilityWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'distributor_id' => ['required', 'uuid'],
            'destination_branch_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
            ...$this->idempotencyRules(),
        ];
    }
}
