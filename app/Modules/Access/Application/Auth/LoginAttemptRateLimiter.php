<?php

namespace App\Modules\Access\Application\Auth;

use App\Models\User;
use App\Modules\Access\Application\Security\RiskCoordinator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Redis;

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

        if (Redis::exists("throttle:penalty:15m:{$email}")) {
            $this->abortWithDelay((int) Redis::ttl("throttle:penalty:15m:{$email}"), 'El acceso MFA está temporalmente restringido.');
        }

        if (Redis::exists("throttle:penalty:60m:{$email}")) {
            $this->abortWithDelay((int) Redis::ttl("throttle:penalty:60m:{$email}"), 'El acceso está temporalmente restringido.');
        }
    }

    public function recordFailedLogin(string $email, string $ip, string $device, ?User $user = null): void
    {
        // 1. Contador por cuenta (en 15m y 24h)
        $accountKey15m = "throttle:account:15m:{$email}";
        $accountKey24h = "throttle:account:24h:{$email}";

        $fails15m = (int) Redis::incr($accountKey15m);
        if ($fails15m === 1) {
            Redis::expire($accountKey15m, self::WINDOW_MINUTES * 60);
        }

        $fails24h = (int) Redis::incr($accountKey24h);
        if ($fails24h === 1) {
            Redis::expire($accountKey24h, self::WINDOW_HOURS * 3600);
        }

        // 2. Incrementar contadores compartidos (IP, Device, Network)
        $this->incrementSharedThresholds($email, $ip, $device);

        // 4. Suspensión de cuenta por intentos excesivos (15 en 24h)
        if ($fails24h >= 15 && $user) {
            $user->update(['state' => 'SECURITY_SUSPENDED']);
            $this->risk->assessAndRespond(
                'AUTHENTICATION_FAILURE_THRESHOLD_REACHED',
                $user,
                null,
                ['recent_failures' => $fails24h, 'compromise_account' => true],
            );
        } elseif ($fails24h === 10) {
            Redis::setex("throttle:penalty:60m:{$email}", 60 * 60, '1');
            $this->abortWithDelay(60 * 60, 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        // 5. Determinar la acción (429) basada en los fallos recientes
        if ($fails15m === 5) {
            Redis::setex("throttle:penalty:15m:{$email}", 15 * 60, '1');
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

        $fails15m = (int) Redis::incr($mfaKey15m);
        if ($fails15m === 1) {
            Redis::expire($mfaKey15m, self::WINDOW_MINUTES * 60);
        }

        $fails24h = (int) Redis::incr($mfaKey24h);
        if ($fails24h === 1) {
            Redis::expire($mfaKey24h, self::WINDOW_HOURS * 3600);
        }

        // 2. Incrementar contadores compartidos (IP, Device)
        $this->incrementSharedThresholds($email, $ip, $device);

        // 3. Suspensión de cuenta (15 en 24h) compartida
        $accountFails24h = (int) Redis::get("throttle:account:24h:{$email}");
        if ($fails24h + $accountFails24h >= 15) {
            $user->update(['state' => 'SECURITY_SUSPENDED']);
            $this->risk->assessAndRespond(
                'MFA_FAILURE_THRESHOLD_REACHED',
                $user,
                null,
                ['recent_failures' => $fails24h + $accountFails24h, 'compromise_account' => true],
            );
        } elseif ($fails24h + $accountFails24h === 10) {
            Redis::setex("throttle:penalty:60m:{$email}", 60 * 60, '1');
            $this->abortWithDelay(60 * 60, 'El acceso está temporalmente restringido.');
        }

        if ($fails15m === 5) {
            Redis::setex("throttle:penalty:15m:{$email}", 15 * 60, '1');
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
        Redis::del("throttle:account:15m:{$email}");
    }

    public function clearMfaAttempts(string $email): void
    {
        Redis::del("throttle:mfa:15m:{$email}");
    }

    private function incrementSharedThresholds(string $email, string $ip, string $device): void
    {
        $ipKey = "throttle:ip:15m:{$ip}";
        $ipFails = (int) Redis::incr($ipKey);
        if ($ipFails === 1) {
            Redis::expire($ipKey, self::WINDOW_MINUTES * 60);
        }
        if ($ipFails === 30) {
            Redis::setex("throttle:penalty:ip:{$ip}", 15 * 60, '1');
        }

        $deviceKey = "throttle:device:15m:{$device}";
        $deviceFails = (int) Redis::incr($deviceKey);
        if ($deviceFails === 1) {
            Redis::expire($deviceKey, self::WINDOW_MINUTES * 60);
        }
        if ($deviceFails === 15) {
            Redis::setex("throttle:penalty:device:{$device}", 15 * 60, '1');
        }

        $subnet = $this->getSubnet($ip);
        if ($subnet) {
            $subnetKey = "throttle:network:15m:{$subnet}";
            $subnetFails = (int) Redis::incr($subnetKey);
            if ($subnetFails === 1) {
                Redis::expire($subnetKey, self::WINDOW_MINUTES * 60);
            }
            if ($subnetFails === 100) {
                Redis::setex("throttle:penalty:network:{$subnet}", 15 * 60, '1');
            }
        }
    }

    private function checkThresholds(string $email, string $ip, string $device): void
    {
        if (Redis::exists("throttle:penalty:ip:{$ip}")) {
            $this->abortWithDelay((int) Redis::ttl("throttle:penalty:ip:{$ip}"), 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        if (Redis::exists("throttle:penalty:device:{$device}")) {
            $this->abortWithDelay((int) Redis::ttl("throttle:penalty:device:{$device}"), 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        if (Redis::exists("throttle:penalty:15m:{$email}")) {
            $this->abortWithDelay((int) Redis::ttl("throttle:penalty:15m:{$email}"), 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        if (Redis::exists("throttle:penalty:60m:{$email}")) {
            $this->abortWithDelay((int) Redis::ttl("throttle:penalty:60m:{$email}"), 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }

        if (Redis::exists('throttle:penalty:network:'.$this->getSubnet($ip))) {
            $this->abortWithDelay((int) Redis::ttl('throttle:penalty:network:'.$this->getSubnet($ip)), 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.');
        }
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
