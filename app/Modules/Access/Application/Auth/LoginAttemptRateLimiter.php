<?php

namespace App\Modules\Access\Application\Auth;

use App\Models\User;
use App\Modules\Access\Application\Security\RiskCoordinator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;

final class LoginAttemptRateLimiter
{
    public function __construct(private readonly RiskCoordinator $risk) {}

    private const WINDOW_MINUTES = 15;

    private const WINDOW_HOURS = 24;

    public function ensureCanAttemptLogin(string $email, string $ip, string $device): void
    {
        $this->checkThresholds($email, $ip, $device);
    }

    public function ensureCanAttemptMfa(string $email, string $ip, string $device): void
    {
        $this->checkThresholds($email, $ip, $device);

        if (($remaining = $this->penaltyRemaining("throttle:penalty:15m:{$email}")) > 0) {
            $this->abortWithDelay($remaining, 'El acceso MFA está temporalmente restringido.');
        }

        if (($remaining = $this->penaltyRemaining("throttle:penalty:60m:{$email}")) > 0) {
            $this->abortWithDelay($remaining, 'El acceso está temporalmente restringido.');
        }
    }

    public function recordFailedLogin(string $email, string $ip, string $device, ?User $user = null): void
    {
        // 1. Contador por cuenta (en 15m y 24h)
        $accountKey15m = "throttle:account:15m:{$email}";
        $accountKey24h = "throttle:account:24h:{$email}";

        $fails15m = $this->increment($accountKey15m, self::WINDOW_MINUTES * 60);
        $fails24h = $this->increment($accountKey24h, self::WINDOW_HOURS * 3600);

        // 2. Incrementar contadores compartidos (IP, Device, Network)
        $this->incrementSharedThresholds($email, $ip, $device);

        // 4. Suspensión de cuenta por intentos excesivos (15 en 24h)
        if ($fails24h >= 15 && $user) {
            $user->forceFill(['state' => 'SECURITY_SUSPENDED'])->save();
            $this->risk->assessAndRespond(
                'AUTHENTICATION_FAILURE_THRESHOLD_REACHED',
                $user,
                null,
                ['recent_failures' => $fails24h, 'compromise_account' => true],
            );
        } elseif ($fails24h === 10) {
            $this->penalize("throttle:penalty:60m:{$email}", 60 * 60);
            $this->abortWithDelay(60 * 60, 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        // 5. Determinar la acción (429) basada en los fallos recientes
        if ($fails15m === 5) {
            $this->penalize("throttle:penalty:15m:{$email}", 15 * 60);
            $this->abortWithDelay(15 * 60, 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        if ($fails15m === 4) {
            $this->abortWithDelay(15, 'No fue posible iniciar sesión con la información proporcionada.');
        }

        if ($fails15m === 3) {
            $this->abortWithDelay(5, 'No fue posible iniciar sesión con la información proporcionada.');
        }
    }

    public function recordFailedMfa(string $email, string $ip, string $device, User $user, callable $invalidateSessionCallback): void
    {
        // 1. Contador MFA por cuenta
        $mfaKey15m = "throttle:mfa:15m:{$email}";
        $mfaKey24h = "throttle:mfa:24h:{$email}";

        $fails15m = $this->increment($mfaKey15m, self::WINDOW_MINUTES * 60);
        $fails24h = $this->increment($mfaKey24h, self::WINDOW_HOURS * 3600);

        // 2. Incrementar contadores compartidos (IP, Device)
        $this->incrementSharedThresholds($email, $ip, $device);

        // 3. Suspensión de cuenta (15 en 24h) compartida
        $accountFails24h = (int) $this->cache()->get("throttle:account:24h:{$email}", 0);
        if ($fails24h + $accountFails24h >= 15) {
            $user->update(['state' => 'SECURITY_SUSPENDED']);
            $this->risk->assessAndRespond(
                'MFA_FAILURE_THRESHOLD_REACHED',
                $user,
                null,
                ['recent_failures' => $fails24h + $accountFails24h, 'compromise_account' => true],
            );
        } elseif ($fails24h + $accountFails24h === 10) {
            $this->penalize("throttle:penalty:60m:{$email}", 60 * 60);
            $this->abortWithDelay(60 * 60, 'El acceso está temporalmente restringido.');
        }

        if ($fails15m === 5) {
            $this->penalize("throttle:penalty:15m:{$email}", 15 * 60);
            $this->abortWithDelay(15 * 60, 'El acceso MFA está temporalmente restringido.');
        }

        // Intento 3: Invalidar desafío actual
        if ($fails15m === 3) {
            $invalidateSessionCallback();
            $this->abortWithDelay(0, 'Demasiados intentos fallidos. Desafío MFA invalidado.');
        }
    }

    public function clearLoginAttempts(string $email): void
    {
        $this->cache()->forget("throttle:account:15m:{$email}");
    }

    public function clearMfaAttempts(string $email): void
    {
        $this->cache()->forget("throttle:mfa:15m:{$email}");
    }

    private function incrementSharedThresholds(string $email, string $ip, string $device): void
    {
        $ipKey = "throttle:ip:15m:{$ip}";
        $ipFails = $this->increment($ipKey, self::WINDOW_MINUTES * 60);
        if ($ipFails === 30) {
            $this->penalize("throttle:penalty:ip:{$ip}", 15 * 60);
        }

        $deviceKey = "throttle:device:15m:{$device}";
        $deviceFails = $this->increment($deviceKey, self::WINDOW_MINUTES * 60);
        if ($deviceFails === 15) {
            $this->penalize("throttle:penalty:device:{$device}", 15 * 60);
        }

        $subnet = $this->getSubnet($ip);
        if ($subnet) {
            $subnetKey = "throttle:network:15m:{$subnet}";
            $subnetFails = $this->increment($subnetKey, self::WINDOW_MINUTES * 60);
            if ($subnetFails === 100) {
                $this->penalize("throttle:penalty:network:{$subnet}", 15 * 60);
            }
        }
    }

    private function checkThresholds(string $email, string $ip, string $device): void
    {
        if (($remaining = $this->penaltyRemaining("throttle:penalty:ip:{$ip}")) > 0) {
            $this->abortWithDelay($remaining, 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        if (($remaining = $this->penaltyRemaining("throttle:penalty:device:{$device}")) > 0) {
            $this->abortWithDelay($remaining, 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        if (($remaining = $this->penaltyRemaining("throttle:penalty:15m:{$email}")) > 0) {
            $this->abortWithDelay($remaining, 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        if (($remaining = $this->penaltyRemaining("throttle:penalty:60m:{$email}")) > 0) {
            $this->abortWithDelay($remaining, 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        if (($remaining = $this->penaltyRemaining('throttle:penalty:network:'.$this->getSubnet($ip))) > 0) {
            $this->abortWithDelay($remaining, 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('access.transient_cache_store'));
    }

    private function increment(string $key, int $ttlSeconds): int
    {
        $count = (int) $this->cache()->increment($key);
        if ($count === 1) {
            $this->cache()->put($key, 1, $ttlSeconds);
        }

        return $count;
    }

    private function penalize(string $key, int $seconds): void
    {
        $this->cache()->put($key, now()->getTimestamp() + $seconds, $seconds);
    }

    private function penaltyRemaining(string $key): int
    {
        $expiresAt = $this->cache()->get($key);

        return is_numeric($expiresAt)
            ? max(0, (int) $expiresAt - now()->getTimestamp())
            : 0;
    }

    private function getSubnet(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.$parts[1].'.'.$parts[2].'.0/24';
        }

        return null; // Omit IPv6 logic for simplicity here
    }

    private function abortWithDelay(int $seconds, string $message): void
    {
        throw new HttpResponseException(
            response()->json(['message' => $message], 429)
                ->header('Retry-After', (string) $seconds)
        );
    }
}
