<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Requests;

/** Valida la evidencia y referencia de una devolución externa ejecutada. */
final class CompleteRefundRequest extends PaymentMutationRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->operationRules(),
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'refunded_at' => ['required', 'date'],
            'method' => ['required', 'string', 'max:80'],
            'reference' => ['required', 'string', 'max:160'],
            'evidence' => ['required', 'file'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
