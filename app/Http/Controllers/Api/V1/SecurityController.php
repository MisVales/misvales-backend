<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ReauthenticatesMfa;
use App\Mail\Security\SecurityAlertMail;
use App\Models\AuthSession;
use App\Models\MfaCredential;
use App\Models\MfaRecoveryCode;
use App\Services\Audit\SecurityAuditService;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionPolicyService;
use App\Services\Auth\SessionTokenIdentifier;
use App\Services\Auth\WebAuthnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use PragmaRX\Google2FA\Google2FA;

class SecurityController extends Controller
{
    use ReauthenticatesMfa;

    /**
     * POST /api/v1/me/security/password
     * Punto 30: Cambio de contraseña con reautenticación.
     */
    public function changePassword(Request $request, SessionPolicyService $policyService, MfaService $mfaService)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            'totp_code' => 'nullable|string|size:6',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 401);
        }

        // Validación de Reautenticación MFA Sensible
        $reauthResult = $this->requireMfaReauth($request);
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
        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'PASSWORD_CHANGE',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
        ]);

        Mail::to($user->email)->queue(
            new SecurityAlertMail(
                $user,
                'Contraseña Modificada',
                'La contraseña de tu cuenta ha sido modificada con éxito.',
                [
                    'ip' => $request->ip(),
                    'device' => app(SecurityAuditService::class)->parseDevice($request->userAgent()),
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

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 401);
        }

        $reauthResult = $this->requireMfaReauth($request);
        if ($reauthResult !== true) {
            return $reauthResult;
        }

        // Revocar/Eliminar viejos códigos
        MfaRecoveryCode::where('user_id', $user->id)->delete();

        // Generar 10 nuevos
        $plainCodes = [];
        $insertData = [];
        $batchId = Str::uuid();

        for ($i = 0; $i < 10; $i++) {
            $code = strtolower(Str::random(4).'-'.Str::random(4));
            $plainCodes[] = $code;
            $insertData[] = [
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'batch_id' => $batchId,
                'code_hash' => hash('sha256', $code),
                'position' => $i + 1,
                'generated_at' => now(),
            ];
        }

        MfaRecoveryCode::insert($insertData);

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'RECOVERY_CODES_REGENERATED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
        ]);

        Mail::to($user->email)->queue(
            new SecurityAlertMail(
                $user,
                'Códigos de Recuperación Regenerados',
                'Tus códigos de recuperación de emergencia han sido regenerados. Los códigos anteriores ya no son válidos.',
                [
                    'ip' => $request->ip(),
                    'device' => app(SecurityAuditService::class)->parseDevice($request->userAgent()),
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
        $google2fa = new Google2FA;
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

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 401);
        }

        $cacheKey = "totp_reconfig_{$user->id}";
        $newSecret = Cache::get($cacheKey);

        if (! $newSecret) {
            return response()->json(['message' => 'La sesión de reconfiguración expiró. Vuelva a solicitar el código QR.'], 400);
        }

        // Validar el NUEVO código
        $google2fa = new Google2FA;
        $valid = $google2fa->verifyKey($newSecret, $request->new_totp_code, config('mfa.totp.window', 1));

        if (! $valid) {
            return response()->json(['message' => 'El código del nuevo autenticador es incorrecto.'], 400);
        }

        // Si es válido, reemplazamos la credencial anterior
        $mfaCredential = MfaCredential::where('user_id', $user->id)->where('type', 'TOTP')->first();

        if (! $mfaCredential) {
            $mfaCredential = new MfaCredential(['user_id' => $user->id, 'type' => 'TOTP']);
        }

        $mfaCredential->secret_ciphertext = Crypt::encryptString($newSecret);
        $mfaCredential->confirmed_at = now();
        $mfaCredential->revoked_at = null;
        $mfaCredential->last_used_at = now();
        $mfaCredential->save();

        Cache::forget($cacheKey);

        // Registrar evento
        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'MFA_RECONFIGURED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
        ]);

        Mail::to($user->email)->queue(
            new SecurityAlertMail(
                $user,
                'Segundo Factor de Autenticación Modificado',
                'Tu aplicación de autenticación (TOTP) ha sido reconfigurada exitosamente.',
                [
                    'ip' => $request->ip(),
                    'device' => app(SecurityAuditService::class)->parseDevice($request->userAgent()),
                    'time' => now()->toDateTimeString(),
                ]
            )
        );

        return response()->json(['message' => 'Su autenticador de dos factores ha sido reconfigurado exitosamente.']);
    }

    /**
     * POST /api/v1/me/security/totp/validate-current
     * Valida el TOTP actual y contraseña antes de permitir reconfigurar.
     */
    public function validateCurrentTotp(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'totp_code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'La contraseña actual es incorrecta.'], 401);
        }

        $mfaCredential = MfaCredential::where('user_id', $user->id)->where('type', 'TOTP')->first();
        if (! $mfaCredential) {
            return response()->json(['message' => 'No tienes TOTP configurado.'], 400);
        }

        $secret = Crypt::decryptString($mfaCredential->secret_ciphertext);
        $mfaService = new MfaService;

        if (! $mfaService->verifyTotp($secret, $request->totp_code, $user->id)) {
            return response()->json(['message' => 'El código de autenticador es incorrecto.'], 401);
        }

        return response()->json(['message' => 'Validación exitosa.']);
    }

    /**
     * Helper Privado: Revoca sesiones diferentes a la actual.
     */
    private function revokeOtherSessions(Request $request)
    {
        $tokenIdentifier = app(SessionTokenIdentifier::class);
        $currentTokenHashes = array_values(array_unique(array_filter([
            $tokenIdentifier->current($request),
            $tokenIdentifier->legacy($request),
        ])));
        $otherSessions = AuthSession::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->whereNotIn('session_identifier_hash', $currentTokenHashes)
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

    /**
     * GET /api/v1/me/security/passkeys
     */
    public function passkeys(Request $request)
    {
        $passkeys = MfaCredential::where('user_id', $request->user()->id)
            ->where('type', 'PASSKEY')
            ->get(['id', 'created_at', 'last_used_at']);

        return response()->json($passkeys);
    }

    /**
     * POST /api/v1/me/security/passkeys/options
     */
    public function passkeyOptions(Request $request, WebAuthnService $webAuthnService)
    {
        $options = $webAuthnService->generateRegistrationOptions($request->user());
        $cacheKey = 'passkey_setup_user_'.$request->user()->id;
        Cache::put($cacheKey, serialize($options), now()->addMinutes(10));

        return response($webAuthnService->serializeOptions($options))->header('Content-Type', 'application/json');
    }

    /**
     * POST /api/v1/me/security/passkeys/register
     */
    public function passkeyRegister(Request $request, WebAuthnService $webAuthnService)
    {
        $request->validate([
            'clientDataJSON' => 'required|string',
            'attestationObject' => 'required|string',
        ]);

        $user = $request->user();
        $cacheKey = 'passkey_setup_user_'.$user->id;
        $cachedOptions = Cache::get($cacheKey);

        if (! $cachedOptions) {
            return response()->json(['error' => 'EXPIRED_PASSKEY_SESSION', 'message' => 'Sesión expirada.'], 400);
        }

        $options = unserialize($cachedOptions);

        try {
            $credentialData = $webAuthnService->verifyRegistration(
                $request->clientDataJSON,
                $request->attestationObject,
                $options
            );

            MfaCredential::create([
                'user_id' => $user->id,
                'type' => 'PASSKEY',
                'credential_identifier' => base64_encode($credentialData->credentialId),
                'public_key' => base64_encode($credentialData->credentialPublicKey ?? ''),
                'aaguid' => (string) $credentialData->aaguid,
                'sign_count' => 0,
                'confirmed_at' => now(),
            ]);

            Cache::forget($cacheKey);

            return response()->json(['message' => 'Passkey registrado correctamente.']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'PASSKEY_REGISTRATION_FAILED', 'message' => 'Error al registrar el Passkey: '.$e->getMessage()], 400);
        }
    }

    /**
     * DELETE /api/v1/me/security/passkeys/{id}
     */
    public function deletePasskey(Request $request, $id)
    {
        $user = $request->user();

        $passkey = MfaCredential::where('user_id', $user->id)
            ->where('type', 'PASSKEY')
            ->where('id', $id)
            ->firstOrFail();

        // Validar que siempre quede al menos 1 método MFA o 1 Passkey.
        $totalMfa = MfaCredential::where('user_id', $user->id)
            ->whereIn('type', ['PASSKEY', 'TOTP'])
            ->count();

        if ($totalMfa <= 1) {
            return response()->json(['error' => 'LAST_MFA_METHOD', 'message' => 'No puedes eliminar esta llave de acceso porque es tu único método de autenticación.'], 400);
        }

        $passkey->delete();

        return response()->json(['message' => 'Llave de acceso eliminada correctamente.']);
    }
}
