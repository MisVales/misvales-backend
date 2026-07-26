<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Requests;

final class ApplyModificationRequest extends VoucherMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->operationHeaders(),
            'lock_version' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'string', 'max:512'],
            'changes' => ['required', 'array', 'min:1', 'max:20'],
            'changes.*' => ['required', 'array:value'],
            'changes.*.value' => ['present'],
        ];
    }
}
