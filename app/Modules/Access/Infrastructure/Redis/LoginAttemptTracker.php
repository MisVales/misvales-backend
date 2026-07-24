<?php

namespace App\Modules\Access\Infrastructure\Redis;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks login attempt failures and enforces limits per spec B06.
 *
 * Dimensions tracked:
 * - Account (continues across IP changes)
 * - IP address
 * - Device
 * - Factor type (password vs MFA)
 *
 * Thresholds per spec B06:
 * Password attempts: 3→5s delay, 4→15s delay, 5 in 15m→15m block, 10 in 24h→60m block + alert
 * MFA attempts: 3→invalidate challenge, 5 in 15m→15m block, 10 in 24h→60m block + alert
 */
final class LoginAttemptTracker
{
    private const ATTEMPT_PREFIX = 'login_attempt:';

    private const BLOCK_PREFIX = 'login_block:';

    private const WINDOW_15_MINUTES = 900;

    private const WINDOW_24_HOURS = 86400;

    /**
     * Record a failed login attempt.
     *
     * @param  int  $userId  User ID (0 for unknown/invalid)
     * @param  string  $ipAddress  Client IP
     * @param  string  $deviceId  Device identifier
     * @param  'password'|'mfa'  $factor  Which factor failed
     */
    public function recordFailure(
        int $userId,
        string $ipAddress,
        string $deviceId,
        string $factor = 'password',
    ): void {
        // Track per account
        if ($userId > 0) {
            $this->incrementCounter("by_account:{$userId}:{$factor}", self::WINDOW_24_HOURS);
        }

        // Track per IP
        $this->incrementCounter("by_ip:{$ipAddress}:{$factor}", self::WINDOW_24_HOURS);

        // Track per device
        if (! empty($deviceId)) {
            $this->incrementCounter("by_device:{$deviceId}:{$factor}", self::WINDOW_24_HOURS);
        }

        // Track per factor + IP for global patterns
        $this->incrementCounter("by_factor:{$factor}:{$ipAddress}", self::WINDOW_15_MINUTES);
    }

    /**
     * Check if an account is currently blocked.
     *
     * @return array{blocked: bool, reason?: string, remaining_seconds?: int}
     */
    public function checkBlock(int $userId, string $ipAddress): array
    {
        $blockKey = self::BLOCK_PREFIX."account:{$userId}";
        $remaining = $this->remainingSeconds($blockKey);

        if ($remaining > 0) {
            return [
                'blocked' => true,
                'reason' => 'account_limit_exceeded',
                'remaining_seconds' => $remaining,
            ];
        }

        // Check IP-level block
        $blockKey = self::BLOCK_PREFIX."ip:{$ipAddress}";
        $remaining = $this->remainingSeconds($blockKey);

        if ($remaining > 0) {
            return [
                'blocked' => true,
                'reason' => 'ip_limit_exceeded',
                'remaining_seconds' => $remaining,
            ];
        }

        return ['blocked' => false];
    }

    /**
     * Get current attempt counts for display/logic.
     *
     * @return array{account_24h: int, ip_24h: int}
     */
    public function getAttemptCounts(int $userId, string $ipAddress, string $factor = 'password'): array
    {
        return [
            'account_24h' => (int) $this->cache()->get(self::ATTEMPT_PREFIX."by_account:{$userId}:{$factor}", 0),
            'ip_24h' => (int) $this->cache()->get(self::ATTEMPT_PREFIX."by_ip:{$ipAddress}:{$factor}", 0),
        ];
    }

    /**
     * Reset failure counters after successful authentication.
     */
    public function resetCounters(int $userId, string $ipAddress): void
    {
        $this->cache()->deleteMultiple([
            self::ATTEMPT_PREFIX."by_account:{$userId}:password",
            self::ATTEMPT_PREFIX."by_account:{$userId}:mfa",
            self::ATTEMPT_PREFIX."by_ip:{$ipAddress}:password",
            self::ATTEMPT_PREFIX."by_ip:{$ipAddress}:mfa",
        ]);
    }

    /**
     * Increment a counter and set TTL if new.
     *
     * @param  string  $key  Counter key
     * @param  int  $ttlSeconds  TTL in seconds
     */
    private function incrementCounter(string $key, int $ttlSeconds): void
    {
        $fullKey = self::ATTEMPT_PREFIX.$key;
        $count = (int) $this->cache()->increment($fullKey);

        // Set TTL only if this is the first increment
        if ($count === 1) {
            $this->cache()->put($fullKey, 1, $ttlSeconds);
        }
    }

    private function remainingSeconds(string $key): int
    {
        $expiresAt = $this->cache()->get($key);

        return is_numeric($expiresAt)
            ? max(0, (int) $expiresAt - now()->getTimestamp())
            : 0;
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('access.transient_cache_store'));
    }
}
