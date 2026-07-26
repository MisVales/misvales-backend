<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;

/** Evita copiar identificadores sensibles completos a motivos y descripciones. */
final class SensitiveReasonGuard
{
    public function sanitize(string $value, string $field = 'reason'): string
    {
        $sanitized = preg_replace('/\s+/u', ' ', trim(strip_tags($value))) ?? '';
        if (
            $sanitized === ''
            || mb_strlen($sanitized) > 500
            || preg_match('/\b[A-Z]{4}\d{6}[A-Z0-9]{8}\b/i', $sanitized) === 1
            || preg_match('/\b\d{16,20}\b/', $sanitized) === 1
        ) {
            throw VoucherDomainException::fieldNotAllowed($field);
        }

        return $sanitized;
    }
}
