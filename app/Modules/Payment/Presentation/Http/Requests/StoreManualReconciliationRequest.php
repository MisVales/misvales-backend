<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Requests;

/** Valida la solicitud y evidencia de una conciliación manual. */
final class StoreManualReconciliationRequest extends PaymentMutationRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'bank_movement_id' => ['required', 'uuid'],
            'relation_id' => ['required', 'string', 'max:128'],
            'clarification_id' => ['sometimes', 'nullable', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence' => ['required', 'file'],
        ];
    }
}
