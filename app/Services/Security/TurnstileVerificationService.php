<?php

namespace App\Services\Security;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerificationService
{
    /**
     * Valida el token de Cloudflare Turnstile en base a la configuración y requerimientos de seguridad.
     *
     * @param Request $request
     * @param string|null $token
     * @return bool
     *
     * @throws ApiException
     */
    public function verify(Request $request, ?string $token): bool
    {
        $secret = config('services.turnstile.secret');
        $hasSecret = !empty($secret) && is_string($secret) && trim($secret) !== '';
        $hasToken = !empty($token) && is_string($token) && trim($token) !== '';

        // Caso A: Turnstile deshabilitado en backend y no enviado por cliente
        if (!$hasSecret && !$hasToken) {
            return true;
        }

        // Caso D: Turnstile habilitado en backend pero el cliente no envió token
        if ($hasSecret && !$hasToken) {
            throw new ApiException(
                'TURNSTILE_REQUIRED',
                'La verificación de seguridad es obligatoria.',
                422
            );
        }

        // Caso C: Inconsistencia (Frontend envió token pero el backend no tiene TURNSTILE_SECRET)
        if (!$hasSecret && $hasToken) {
            Log::critical('Inconsistencia Turnstile: Se recibió turnstile_token pero TURNSTILE_SECRET no está configurado en el servidor.');
            throw new ApiException(
                'CONFIG_ERROR',
                'Error de configuración en el servicio de seguridad.',
                500
            );
        }

        // Caso B: Validación activa contra Cloudflare
        $verifyUrl = config('services.turnstile.url', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        $verifySsl = (bool) config('services.turnstile.verify_ssl', true);

        $remoteIp = $request->header('CF-Connecting-IP') ?? $request->ip();
        $payload = [
            'secret' => $secret,
            'response' => $token,
        ];

        // Solo enviar remoteip si es una IP pública válida del visitante
        if ($remoteIp && filter_var($remoteIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $payload['remoteip'] = $remoteIp;
        }

        try {
            $httpClient = Http::asForm()
                ->timeout(10)
                ->connectTimeout(5);

            if (! $verifySsl || ! app()->isProduction()) {
                $httpClient = $httpClient->withoutVerifying();
            }

            $response = $httpClient->post($verifyUrl, $payload);

            if (!$response->successful() || !$response->json('success')) {
                $errorCodes = $response->json('error-codes', []);
                Log::warning('Fallo en validación de Turnstile', [
                    'ip' => $request->ip(),
                    'error_codes' => $errorCodes,
                ]);

                throw new ApiException(
                    'INVALID_TURNSTILE',
                    'La verificación de seguridad es inválida o ha expirado.',
                    422
                );
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Error de comunicación con Cloudflare Turnstile', [
                'error' => $e->getMessage(),
            ]);

            throw new ApiException(
                'INVALID_TURNSTILE',
                'No fue posible verificar el desafío de seguridad.',
                422
            );
        }

        return true;
    }
}
