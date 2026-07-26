<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Requests;

final class ReleaseVoucherRequest extends VoucherMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->operationHeaders(),
            'lock_version' => ['required', 'integer', 'min:1'],
            'checks' => ['required', 'array:identity_verified,address_verified,identification_verified,proof_of_address_verified,bank_account_verified'],
            'checks.identity_verified' => ['required', 'accepted'],
            'checks.address_verified' => ['required', 'accepted'],
            'checks.identification_verified' => ['required', 'accepted'],
            'checks.proof_of_address_verified' => ['required', 'accepted'],
            'checks.bank_account_verified' => ['required', 'accepted'],
        ];
    }
}
