<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Requests;

/** Valida una mutación que requiere motivo y versión del recurso. */
final class ReasonedPaymentRequest extends PaymentMutationRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'authorization_id' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }
}
