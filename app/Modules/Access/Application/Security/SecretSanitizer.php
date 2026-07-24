<?php

namespace App\Modules\Access\Application\Security;

final class SecretSanitizer
{
    private const SENSITIVE_KEY = '/(?:password|secret|token|authorization|cookie|totp|recovery.?code|webauthn|assertion|client.?data|signature|attestation|refresh|passkey)/i';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY, (string) $key) === 1) {
                continue;
            }

            $clean[$key] = $this->sanitizeValue($value);
        }

        return $clean;
    }

    public function containsSecret(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (preg_match(self::SENSITIVE_KEY, (string) $key) === 1 || $this->containsSecret($item)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($value)) {
            return false;
        }

        return preg_match('/(?:Bearer\s+\S+|\$2[ayb]\$\d{2}\$|\$argon2(?:id|i|d)\$|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)/', $value) === 1;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->sanitizeValue($item), $value);
            }

            return $this->sanitize($value);
        }

        return $this->containsSecret($value) ? '[REDACTED]' : $value;
    }
}
