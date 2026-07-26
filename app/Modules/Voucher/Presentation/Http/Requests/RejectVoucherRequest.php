<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Requests;

use App\Modules\Voucher\Domain\Enums\VoucherRejectionReason;
use Illuminate\Validation\Rule;

final class RejectVoucherRequest extends VoucherMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->operationHeaders(),
            'lock_version' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', Rule::enum(VoucherRejectionReason::class)],
            'description' => ['required', 'string', 'max:500'],
        ];
    }
}
