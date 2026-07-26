<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class VoucherMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            'idempotency_key' => $this->header('Idempotency-Key'),
            'request_id' => $this->header('X-Request-Id'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function operationHeaders(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:200'],
            'request_id' => ['required', 'uuid'],
        ];
    }
}
