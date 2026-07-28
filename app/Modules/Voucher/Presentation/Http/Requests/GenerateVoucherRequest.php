<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Requests;

final class GenerateVoucherRequest extends VoucherMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->operationHeaders(),
            'client_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
        ];
    }
}
