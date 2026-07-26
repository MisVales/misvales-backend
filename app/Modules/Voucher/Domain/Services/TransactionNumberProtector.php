<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Services;

use App\Modules\Client\Domain\Security\SensitiveDataProtector;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use RuntimeException;
use SensitiveParameter;

/** Protege la referencia externa sin imponer un formato funcional no definido. */
final readonly class TransactionNumberProtector
{
    public function __construct(private SensitiveDataProtector $protector) {}

    /** @return array{encrypted:string,hmac:string,masked:string} */
    public function protect(#[SensitiveParameter] string $value): array
    {
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized) > 255) {
            throw VoucherDomainException::transactionRequired();
        }

        $key = config('voucher.transaction_hmac_key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('VOUCHER_TRANSACTION_HMAC_KEY is not configured.');
        }

        return [
            'encrypted' => $this->protector->encrypt($normalized),
            'hmac' => hash_hmac('sha256', $normalized, $key),
            'masked' => str_repeat('*', max(0, mb_strlen($normalized) - 4)).mb_substr($normalized, -4),
        ];
    }
}
