<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;

class MfaService
{
    /**
     * Verifica un código TOTP contra un secreto.
     * Incluye prevención de Replay Attacks (códigos ya usados).
     *
     * @param string $secret El secreto TOTP (ya desencriptado)
     * @param string $code El código de 6 dígitos introducido por el usuario
     * @param string $userId El ID del usuario intentando usar el código
     * @param int $window Ventana de tolerancia para clock drift (1 = +- 30 segundos)
     * @return bool
     */
    public function verifyTotp(string $secret, string $code, string $userId, int $window = 1): bool
    {
        // 1. Prevención de Replay Attack
        // Revisar si este usuario ya usó este código exitosamente en los últimos 2 minutos
        $cacheKey = "totp_used_{$userId}_{$code}";
        
        if (Cache::has($cacheKey)) {
            return false; // El código ya fue "quemado"
        }

        // 2. Validación estándar RFC 6238
        $google2fa = new Google2FA();
        $google2fa->setWindow($window);
        
        $isValid = $google2fa->verifyKey($secret, $code);

        // 3. Si es válido, "quemarlo" en la caché
        if ($isValid) {
            // El código de Google Authenticator cambia cada 30 segundos,
            // y con una ventana de 1, su tiempo de vida máximo aceptable es de unos 60-90s.
            // Guardarlo por 120 segundos es seguro para evitar que vuelva a usarse.
            Cache::put($cacheKey, true, now()->addSeconds(120));
        }

        return $isValid;
    }
}
