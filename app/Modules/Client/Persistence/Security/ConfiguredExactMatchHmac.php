<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Security;

use App\Modules\Client\Domain\Security\ExactMatchHmac;
use RuntimeException;

/** HMAC SHA-256 con secreto separado y configurable para M06. */
final class ConfiguredExactMatchHmac implements ExactMatchHmac
{
    public function make(string $normalizedValue): string
    {
        $key = config('client.hmac_key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('CLIENT_HMAC_KEY is not configured.');
        }

        return hash_hmac('sha256', $normalizedValue, $key);
    }
}
