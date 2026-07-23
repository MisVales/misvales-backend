<?php

namespace App\Modules\Access\Application\MFA;

use App\Modules\Access\Domain\MFA\MfaType;
use OTPHP\TOTP;

/**
 * Verifies TOTP (Time-based One-Time Password) codes.
 * 
 * Per spec B05.2:
 * - 6 digits, 30-second period
 * - Tolerance: ±1 period
 * - Prevent reuse of already-accepted TOTP in its window
 * - Generate secret with secure function, show only during enrollment
 * - Encrypt secret separately from database
 * - Never persist QR or secret in logs
 * - Confirm enrollment only after validating first code
 * - Do not remove TOTP if it would leave account without passkey or TOTP
 */
final class TotpVerifier
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const WINDOW = 1; // ±1 period tolerance per spec

    /**
     * Generate a new TOTP secret for user enrollment.
     * 
     * @return array{secret: string, uri: string}
     */
    public function generateSecret(string $userEmail, string $issuer = 'MisVales'): array
    {
        $totp = TOTP::create(
            secret: bin2hex(random_bytes(32)),
            label: $userEmail,
            issuer: $issuer,
            digits: self::DIGITS,
            digest: 'sha1',
            period: self::PERIOD,
        );

        return [
            'secret' => $totp->getSecret(),
            'uri' => $totp->getProvisioningUri(),
        ];
    }

    /**
     * Verify a TOTP code against a secret.
     * 
     * @param string $secret The TOTP secret (unencrypted)
     * @param string $code The 6-digit code to verify
     * @return bool True if code is valid
     */
    public function verify(string $secret, string $code): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            $totp = TOTP::create(
                secret: $secret,
                digits: self::DIGITS,
                digest: 'sha1',
                period: self::PERIOD,
            );

            // Verify with window tolerance per spec B05.2
            return $totp->verify($code, null, self::WINDOW);
        } catch (\Exception) {
            return false;
        }
    }
}
