<?php

namespace App\Http\Traits;

use App\Models\AuthSession;
use App\Models\MfaCredential;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

trait ReauthenticatesMfa
{
    /**
     * Exige reautenticación MFA para acciones sensibles.
     *
     * @return bool|JsonResponse True si aprueba, JSON Response si falla o requiere reauth.
     */
    protected function requireMfaReauth(Request $request)
    {
        $policyService = app(SessionPolicyService::class);
        $mfaService = app(MfaService::class);

        $user = $request->user();
        $tokenHash = hash('sha256', $request->bearerToken());
        $session = AuthSession::where('session_identifier_hash', $tokenHash)->first();

        if (! $session) {
            return response()->json(['message' => 'Sesión no encontrada.'], 401);
        }

        $policy = $policyService->getPolicyForUser($user);

        // Si no han pasado suficientes minutos, no pedimos MFA
        if ($session->mfa_verified_at && $session->mfa_verified_at->diffInMinutes(now()) <= $policy['mfa_reauth']) {
            return true;
        }

        // Si superó el tiempo, se requiere código MFA en la petición actual
        if (! $request->totp_code) {
            return response()->json([
                'mfa_required' => true,
                'message' => 'Por seguridad, ingrese un código TOTP actual para confirmar esta acción.',
            ], 403);
        }

        // Validar el código TOTP provisto
        $mfaCredential = MfaCredential::where('user_id', $user->id)->where('type', 'TOTP')->where('is_active', true)->first();
        if (! $mfaCredential) {
            return response()->json(['message' => 'No hay configuración MFA activa.'], 403);
        }

        $secret = Crypt::decryptString($mfaCredential->secret_ciphertext);
        if (! $mfaService->verifyTotp($secret, $request->totp_code, $user->id)) {
            return response()->json(['message' => 'El código autenticador es incorrecto o expirado.'], 401);
        }

        // Actualizamos la marca de tiempo de MFA en la sesión
        $session->mfa_verified_at = now();
        $session->save();

        return true;
    }
}
