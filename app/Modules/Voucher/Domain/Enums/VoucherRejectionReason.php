<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

enum VoucherRejectionReason: string
{
    case IDENTITY_MISMATCH = 'IDENTITY_MISMATCH';
    case DOCUMENTS_MISMATCH = 'DOCUMENTS_MISMATCH';
    case BANK_ACCOUNT_MISMATCH = 'BANK_ACCOUNT_MISMATCH';
    case INFORMATION_MISMATCH = 'INFORMATION_MISMATCH';
}
