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
     *
     * @throws ApiException
     */
    public function verify(Request $request, ?string $token): bool
    {
        if (! (bool) config('services.turnstile.enabled', false)) {
            return true;
        }

        $secret = trim((string) config('services.turnstile.secret'), " \t\n\r\0\x0B\"'");
        $token = trim((string) $token, " \t\n\r\0\x0B\"'");
        $hasSecret = $secret !== '';
        $hasToken = $token !== '';

        // Caso D: Turnstile habilitado en backend pero el cliente no envió token
        if ($hasSecret && ! $hasToken) {
            throw new ApiException(
                'TURNSTILE_REQUIRED',
                'La verificación de seguridad es obligatoria.',
                422
            );
        }

        if (! $hasSecret) {
            Log::critical('Turnstile está habilitado pero TURNSTILE_SECRET no está configurado.');
            throw new ApiException(
                'CONFIG_ERROR',
                'Error de configuración en el servicio de seguridad.',
                500
            );
        }

        // Caso B: Validación activa contra Cloudflare
        $verifyUrl = config('services.turnstile.url', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        $verifySsl = (bool) config('services.turnstile.verify_ssl', true);
        $caBundle = config('services.turnstile.ca_bundle');

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

            if (is_string($caBundle) && trim($caBundle) !== '') {
                $httpClient = $httpClient->withOptions(['verify' => $caBundle]);
            }

            if (! $verifySsl || ! app()->isProduction()) {
                $httpClient = $httpClient->withoutVerifying();
            }

            $response = $httpClient->post($verifyUrl, $payload);

            // Si falló con remoteip, reintentar sin remoteip (evita falsos negativos en VPN/IPv6)
            if ((! $response->successful() || ! $response->json('success')) && isset($payload['remoteip'])) {
                unset($payload['remoteip']);
                $response = $httpClient->post($verifyUrl, $payload);
            }

            if (! $response->successful() || ! $response->json('success')) {
                $errorCodes = $response->json('error-codes', []);
                Log::warning('Fallo en validación de Turnstile', [
                    'ip' => $request->ip(),
                    'error_codes' => $errorCodes,
                    'cf_response' => $response->json(),
                ]);

                throw new ApiException(
                    'INVALID_TURNSTILE',
                    'La verificación de seguridad es inválida o ha expirado.',
                    422,
                    [],
                    ['cloudflare_errors' => $errorCodes]
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
