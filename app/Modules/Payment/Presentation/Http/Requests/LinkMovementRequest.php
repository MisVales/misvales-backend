<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Requests;

/** Valida el vínculo optimista entre una aclaración y un movimiento. */
final class LinkMovementRequest extends PaymentMutationRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'bank_movement_id' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
