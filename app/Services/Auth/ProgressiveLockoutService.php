<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;

class ProgressiveLockoutService
{
    /**
     * Comprueba si la IP+Email está bloqueada. Si lo está, devuelve los segundos restantes.
     */
    public function checkLockout(string $ip, string $email): ?int
    {
        $lockKey = "progressive_lock_{$ip}_{$email}";
        if (Cache::has($lockKey)) {
            // Extraemos el timestamp de expiración guardado como valor
            $expiresAt = Cache::get($lockKey);
            $remaining = $expiresAt - time();

            return $remaining > 0 ? $remaining : null;
        }

        return null;
    }

    /**
     * Registra un intento fallido y evalúa si aplica bloqueo progresivo ciego.
     */
    public function recordFailedAttempt(string $ip, string $email): void
    {
        $attemptsKey = "failed_attempts_{$ip}_{$email}";
        $lockKey = "progressive_lock_{$ip}_{$email}";

        // Incrementamos intentos fallidos (duran 1 hora en cache para el conteo global)
        $attempts = Cache::increment($attemptsKey);
        if ($attempts === 1) {
            Cache::put($attemptsKey, 1, now()->addHour());
        }

        // Sistema de castigo progresivo
        if ($attempts >= 20) {
            Cache::put($lockKey, time() + 900, now()->addMinutes(15)); // 15 minutos
        } elseif ($attempts >= 15) {
            Cache::put($lockKey, time() + 300, now()->addMinutes(5)); // 5 minutos
        } elseif ($attempts >= 10) {
            Cache::put($lockKey, time() + 60, now()->addMinutes(1)); // 1 minuto
        }
    }

    /**
     * Limpia los contadores cuando el inicio de sesión es exitoso.
     */
    public function clearLockout(string $ip, string $email): void
    {
        Cache::forget("failed_attempts_{$ip}_{$email}");
        Cache::forget("progressive_lock_{$ip}_{$email}");
    }
}
