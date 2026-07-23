<?php

namespace App\Modules\Access\Application\MFA;

use SensitiveParameter;

final class TotpVerifier
{
    public function verify(#[SensitiveParameter] string $base32Secret, #[SensitiveParameter] string $code, ?int $timestamp = null): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        $secret = $this->decodeBase32($base32Secret);
        if ($secret === null) {
            return false;
        }
        $counter = intdiv($timestamp ?? time(), 30);
        foreach ([-1, 0, 1] as $window) {
            $binary = hash_hmac('sha1', pack('N2', 0, $counter + $window), $secret, true);
            $offset = ord($binary[19]) & 0x0F;
            $number = ((ord($binary[$offset]) & 0x7F) << 24)
                | ((ord($binary[$offset + 1]) & 0xFF) << 16)
                | ((ord($binary[$offset + 2]) & 0xFF) << 8)
                | (ord($binary[$offset + 3]) & 0xFF);
            if (hash_equals(str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT), $code)) {
                return true;
            }
        }

        return false;
    }

    private function decodeBase32(string $value): ?string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $value = strtoupper(str_replace([' ', '-'], '', $value));
        $bits = '';
        foreach (str_split($value) as $character) {
            $index = strpos($alphabet, $character);
            if ($index === false) {
                return null;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
