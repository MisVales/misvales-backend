<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Requests;

final class RequestModificationRequest extends VoucherMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->operationHeaders(),
            'lock_version' => ['required', 'integer', 'min:1'],
            'fields' => ['required', 'array', 'min:1', 'max:20'],
            'fields.*' => ['required', 'string', 'distinct', 'max:160'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
