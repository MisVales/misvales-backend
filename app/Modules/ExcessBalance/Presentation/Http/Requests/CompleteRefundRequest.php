<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Presentation\Http\Requests;

final class CompleteRefundRequest extends ExcessMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->baseRules(),
            'refund_date' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', 'string', 'max:80'],
            'reference' => ['required', 'string', 'max:160'],
            'method_fields' => ['sometimes', 'array', 'max:50'],
            'evidence' => ['sometimes', 'file'],
        ];
    }
}
