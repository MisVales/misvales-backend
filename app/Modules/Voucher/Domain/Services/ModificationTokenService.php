<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Services;

use RuntimeException;
use SensitiveParameter;

/** Generación y comparación centralizada de tokens técnicos de M09. */
final class ModificationTokenService
{
    private const TTL_SECONDS = 300;

    /** @return array{plain:string, hash:string} */
    public function issue(): array
    {
        $bytes = max(32, min(64, (int) config('voucher.modification_token_bytes', 32)));
        $plain = rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');

        return ['plain' => $plain, 'hash' => $this->hash($plain)];
    }

    public function matches(string $expectedHash, #[SensitiveParameter] string $plain): bool
    {
        return hash_equals($expectedHash, $this->hash($plain));
    }

    public function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }

    private function hash(#[SensitiveParameter] string $plain): string
    {
        $key = config('voucher.token_hash_key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('VOUCHER_TOKEN_HASH_KEY is not configured.');
        }

        return hash_hmac('sha256', $plain, $key);
    }
}
