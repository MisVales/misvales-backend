<?php

namespace App\Modules\Access\Infrastructure\Redis;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * Manages transient MFA authentication sessions in Redis.
 *
 * Per spec B06:
 * - Created after Stage 1 (email/password OK)
 * - 5-minute validity window
 * - Single-use for MFA verification
 * - Tied to account, IP, app, device, and allowed methods
 * - Does NOT expose whether password or account was valid
 * - Expires → restart from email/password
 */
final class MfaSessionManager
{
    private const MFA_SESSION_TTL_MINUTES = 5;

    private const SESSION_PREFIX = 'mfa_session:';

    private const USER_INDEX_PREFIX = 'mfa_session_user:';

    /**
     * Create a new MFA transaction session after password verification.
     *
     * @param  User  $user  Authenticated user
     * @param  string  $ipAddress  Client IP
     * @param  string  $deviceId  Device identifier
     * @param  array<string>  $allowedFactors  Available MFA factors for this user
     * @return array{auth_token: string, expires_at: string}
     */
    public function createSession(
        User $user,
        string $ipAddress,
        string $deviceId,
        array $allowedFactors,
    ): array {
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = CarbonImmutable::now()->addMinutes(self::MFA_SESSION_TTL_MINUTES);

        $sessionData = [
            'user_id' => $user->id,
            'user_public_id' => $user->public_id,
            'ip_address' => $ipAddress,
            'device_id' => $deviceId,
            'allowed_factors' => $allowedFactors,
            'created_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        Redis::setex(
            self::SESSION_PREFIX.$tokenHash,
            self::MFA_SESSION_TTL_MINUTES * 60,
            json_encode($sessionData),
        );
        Redis::sadd(self::USER_INDEX_PREFIX.$user->id, self::SESSION_PREFIX.$tokenHash);
        Redis::expire(self::USER_INDEX_PREFIX.$user->id, self::MFA_SESSION_TTL_MINUTES * 60);

        return [
            'auth_token' => $plainToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Retrieve and validate an MFA session.
     *
     * @param  string  $plainToken  The auth token from client
     * @return array<string, mixed>|null
     */
    public function getSession(string $plainToken): ?array
    {
        $tokenHash = hash('sha256', $plainToken);
        $data = Redis::get(self::SESSION_PREFIX.$tokenHash);

        if ($data === null) {
            return null;
        }

        $session = json_decode((string) $data, true);

        return is_array($session) ? $session : null;
    }

    /**
     * Mark an MFA session as used (completed).
     * Removes from Redis to prevent reuse.
     */
    public function consumeSession(string $plainToken): bool
    {
        $tokenHash = hash('sha256', $plainToken);
        $session = $this->getSession($plainToken);
        if (is_array($session) && is_int($session['user_id'] ?? null)) {
            Redis::srem(self::USER_INDEX_PREFIX.$session['user_id'], self::SESSION_PREFIX.$tokenHash);
        }

        return Redis::del(self::SESSION_PREFIX.$tokenHash) > 0;
    }

    /**
     * Invalidate all MFA sessions for a user (e.g., after password change).
     */
    public function invalidateUserSessions(int $userId): void
    {
        $indexKey = self::USER_INDEX_PREFIX.$userId;
        $keys = Redis::smembers($indexKey);
        if (! is_array($keys)) {
            Redis::del($indexKey);

            return;
        }

        foreach ($keys as $key) {
            Redis::del($key);
        }

        Redis::del($indexKey);
    }
}
