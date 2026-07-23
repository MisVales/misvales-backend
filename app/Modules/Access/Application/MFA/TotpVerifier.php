<?php

namespace App\Modules\Access\Application\MFA;

use Illuminate\Support\Facades\Redis;
use OTPHP\TOTP;

/**
 * Verifies TOTP (Time-based One-Time Password) codes with replay protection.
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

    private const REPLAY_PROTECTION_TTL = self::PERIOD * 3;

    /**
     * Generate a new TOTP secret for user enrollment.
     *
     * @return array{secret: string, uri: string}
     */
    public function generateSecret(string $userEmail, string $issuer = 'MisVales'): array
    {
        $totp = TOTP::create(
            secret: null,
            period: self::PERIOD,
            digest: 'sha1',
            digits: self::DIGITS,
            secretSize: 32,
        );
        $totp->setLabel($userEmail);
        $totp->setIssuer($issuer);

        return [
            'secret' => $totp->getSecret(),
            'uri' => $totp->getProvisioningUri(),
        ];
    }

    /**
     * Verify a TOTP code against a secret at a deterministic timestamp.
     */
    public function verifyAt(string $secret, string $code, int $timestamp, ?string $credentialHash = null): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            $totp = TOTP::create(
                secret: $secret,
                period: self::PERIOD,
                digest: 'sha1',
                digits: self::DIGITS,
            );

            $matchedTimestamp = $this->matchingTimestamp($totp, $code, $timestamp);
            if ($matchedTimestamp === null) {
                return false;
            }

            if ($credentialHash !== null && ! $this->markCodeAsUsed($credentialHash, $code, $matchedTimestamp)) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verify a TOTP code against a secret with replay protection.
     *
     * @param  string  $secret  The decrypted TOTP secret
     * @param  string  $code  The 6-digit code to verify
     * @param  string|null  $credentialHash  Stable credential hash for replay tracking
     */
    public function verify(string $secret, string $code, ?string $credentialHash = null): bool
    {
        return $this->verifyAt($secret, $code, time(), $credentialHash);
    }

    private function matchingTimestamp(TOTP $totp, string $code, int $timestamp): ?int
    {
        foreach ([-self::PERIOD, 0, self::PERIOD] as $offset) {
            $candidate = $timestamp + $offset;
            if ($candidate >= 0 && $totp->verify($code, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function markCodeAsUsed(string $credentialHash, string $code, int $matchedTimestamp): bool
    {
        $timeStep = intdiv($matchedTimestamp, self::PERIOD);
        $replayKey = "totp:used:{$credentialHash}:{$timeStep}:{$code}";
        $stored = Redis::command('set', [$replayKey, '1', 'EX', self::REPLAY_PROTECTION_TTL, 'NX']);

        return $stored === true || $stored === 'OK';
    }
}
