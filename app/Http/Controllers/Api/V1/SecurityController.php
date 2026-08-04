<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use App\Models\MfaCredential;
use App\Models\MfaRecoveryCode;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class SecurityController extends Controller
{
    /**
     * POST /api/v1/me/security/password
     * Punto 30: Cambio de contraseña con reautenticación.
     */
    public function changePassword(Request $request, SessionPolicyService $policyService, MfaService $mfaService)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:12|confirmed',
            'totp_code' => 'nullable|string|size:6',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 401);
        }

        // Validación de Reautenticación MFA Sensible
        $reauthResult = $this->requireMfaReauth($request, $user, $policyService, $mfaService);
        if ($reauthResult !== true) {
            return $reauthResult;
        }

        // Actualizar contraseña
        $user->password = Hash::make($request->new_password);
        $user->password_changed_at = now();
        $user->save();

        // Revocar otras sesiones
        $this->revokeOtherSessions($request);

        // Registrar evento
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'PASSWORD_CHANGE',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
        ]);
        
        \Illuminate\Support\Facades\Mail::to($user->email)->queue(
            new \App\Mail\Security\SecurityAlertMail(
                $user,
                'Contraseña Modificada',
                'La contraseña de tu cuenta ha sido modificada con éxito.',
                [
                    'ip' => $request->ip(),
                    'device' => app(\App\Services\Audit\SecurityAuditService::class)->parseDevice($request->userAgent()),
                    'time' => now()->toDateTimeString(),
                ]
            )
        );

        return response()->json(['message' => 'Contraseña actualizada exitosamente. Se han cerrado las demás sesiones por seguridad.']);
    }

    /**
     * POST /api/v1/me/security/recovery-codes
     * Punto 31: Regenerar códigos de recuperación.
     */
    public function regenerateRecoveryCodes(Request $request, SessionPolicyService $policyService, MfaService $mfaService)
    {
        $request->validate([
            'current_password' => 'required|string',
            'totp_code' => 'nullable|string|size:6',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 401);
        }

        $reauthResult = $this->requireMfaReauth($request, $user, $policyService, $mfaService);
        if ($reauthResult !== true) return $reauthResult;

        // Revocar/Eliminar viejos códigos
        MfaRecoveryCode::where('user_id', $user->id)->delete();

        // Generar 10 nuevos
        $plainCodes = [];
        $insertData = [];

        for ($i = 0; $i < 10; $i++) {
            $code = strtolower(Str::random(4) . '-' . Str::random(4));
            $plainCodes[] = $code;
            $insertData[] = [
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'code_hash' => hash('sha256', $code),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        MfaRecoveryCode::insert($insertData);
        
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'RECOVERY_CODES_REGENERATED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
        ]);
        
        \Illuminate\Support\Facades\Mail::to($user->email)->queue(
            new \App\Mail\Security\SecurityAlertMail(
                $user,
                'Códigos de Recuperación Regenerados',
                'Tus códigos de recuperación de emergencia han sido regenerados. Los códigos anteriores ya no son válidos.',
                [
                    'ip' => $request->ip(),
                    'device' => app(\App\Services\Audit\SecurityAuditService::class)->parseDevice($request->userAgent()),
                    'time' => now()->toDateTimeString(),
                ]
            )
        );

        return response()->json([
            'message' => 'Nuevos códigos de recuperación generados. Los códigos anteriores han sido invalidados.',
            'recovery_codes' => $plainCodes,
        ]);
    }

    /**
     * GET /api/v1/me/security/totp/setup
     * Punto 32 (Fase 1): Iniciar reconfiguración TOTP.
     */
    public function totpSetup(Request $request)
    {
        $user = $request->user();
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        // Guardar temporalmente el nuevo secreto en caché (15 min)
        $cacheKey = "totp_reconfig_{$user->id}";
        Cache::put($cacheKey, $secret, now()->addMinutes(15));

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->normalized_email,
            $secret
        );

        return response()->json([
            'message' => 'Escanee este código con su aplicación de autenticación para vincular su nuevo dispositivo.',
            'totp_secret' => $secret,
            'totp_uri' => $qrCodeUrl,
        ]);
    }

    /**
     * POST /api/v1/me/security/totp/confirm
     * Punto 32 (Fase 2): Confirmar el nuevo dispositivo.
     */
    public function totpConfirm(Request $request, MfaService $mfaService)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_totp_code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 401);
        }

        $cacheKey = "totp_reconfig_{$user->id}";
        $newSecret = Cache::get($cacheKey);

        if (!$newSecret) {
            return response()->json(['message' => 'La sesión de reconfiguración expiró. Vuelva a solicitar el código QR.'], 400);
        }

        // Validar el NUEVO código
        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($newSecret, $request->new_totp_code, config('mfa.totp.window', 1));

        if (!$valid) {
            return response()->json(['message' => 'El código del nuevo autenticador es incorrecto.'], 400);
        }

        // Si es válido, reemplazamos la credencial anterior
        $mfaCredential = MfaCredential::where('user_id', $user->id)->where('type', 'TOTP')->first();
        
        if (!$mfaCredential) {
            $mfaCredential = new MfaCredential(['user_id' => $user->id, 'type' => 'TOTP']);
        }

        $mfaCredential->secret_ciphertext = Crypt::encryptString($newSecret);
        $mfaCredential->is_active = true;
        $mfaCredential->last_used_at = now();
        $mfaCredential->save();

        Cache::forget($cacheKey);

        // Registrar evento
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'MFA_RECONFIGURED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
        ]);
        
        \Illuminate\Support\Facades\Mail::to($user->email)->queue(
            new \App\Mail\Security\SecurityAlertMail(
                $user,
                'Segundo Factor de Autenticación Modificado',
                'Tu aplicación de autenticación (TOTP) ha sido reconfigurada exitosamente.',
                [
                    'ip' => $request->ip(),
                    'device' => app(\App\Services\Audit\SecurityAuditService::class)->parseDevice($request->userAgent()),
                    'time' => now()->toDateTimeString(),
                ]
            )
        );

        return response()->json(['message' => 'Su autenticador de dos factores ha sido reconfigurado exitosamente.']);
    }

    /**
     * Helper Privado: Ejecuta la validación de la política MFA Reauth
     * @return bool|\Illuminate\Http\JsonResponse True si aprueba, JSON Response si falla o requiere reauth.
     */
    private function requireMfaReauth(Request $request, $user, SessionPolicyService $policyService, MfaService $mfaService)
    {
        $tokenHash = hash('sha256', $request->bearerToken());
        $session = AuthSession::where('session_identifier_hash', $tokenHash)->first();

        if (!$session) return response()->json(['message' => 'Sesión no encontrada.'], 401);

        $policy = $policyService->getPolicyForUser($user);

        // Si no han pasado suficientes minutos, no pedimos MFA
        if ($session->mfa_verified_at && $session->mfa_verified_at->diffInMinutes(now()) <= $policy['mfa_reauth']) {
            return true;
        }

        // Si superó el tiempo, se requiere código MFA en la petición actual
        if (!$request->totp_code) {
            return response()->json([
                'mfa_required' => true,
                'message' => 'Por seguridad, ingrese un código TOTP actual para confirmar esta acción.',
            ], 403);
        }

        // Validar el código TOTP provisto
        $mfaCredential = MfaCredential::where('user_id', $user->id)->where('type', 'TOTP')->first();
        if (!$mfaCredential) return response()->json(['message' => 'No hay configuración MFA activa.'], 403);

        $secret = Crypt::decryptString($mfaCredential->secret_ciphertext);
        if (!$mfaService->verifyTotp($secret, $request->totp_code, $user->id)) {
            return response()->json(['message' => 'El código autenticador es incorrecto o expirado.'], 401);
        }

        // Actualizamos la marca de tiempo de MFA en la sesión
        $session->mfa_verified_at = now();
        $session->save();

        return true;
    }

    /**
     * Helper Privado: Revoca sesiones diferentes a la actual.
     */
    private function revokeOtherSessions(Request $request)
    {
        $currentTokenHash = hash('sha256', $request->bearerToken());
        $otherSessions = AuthSession::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where('session_identifier_hash', '!=', $currentTokenHash)
            ->get();

        foreach ($otherSessions as $session) {
            $session->update([
                'revoked_at' => now(),
                'revocation_reason' => 'PASSWORD_CHANGE',
                'revoked_by_user_id' => $request->user()->id,
            ]);
            DB::table('personal_access_tokens')->where('token', $session->getRawOriginal('session_identifier_hash'))->delete();
        }
    }
}
