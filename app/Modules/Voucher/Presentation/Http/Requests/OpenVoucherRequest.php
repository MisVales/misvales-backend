<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Requests;

final class OpenVoucherRequest extends VoucherMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->operationHeaders(), 'lock_version' => ['required', 'integer', 'min:1']];
    }
}
