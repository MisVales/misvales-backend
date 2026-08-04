<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountInvitation;
use App\Models\MfaCredential;
use App\Models\MfaRecoveryCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use PragmaRX\Google2FA\Google2FA;

class InvitationController extends Controller
{
    /**
     * POST /api/v1/auth/invitations/inspect
     * Valida la invitación y entrega el token de intercambio y configuración TOTP.
     */
    public function inspect(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $tokenHash = hash('sha256', $request->token);

        $invitation = AccountInvitation::where('token_hash', $tokenHash)->first();

        if (!$invitation) {
            return response()->json(['error' => 'INVALID_INVITATION', 'message' => 'Invitación inválida o no encontrada.'], 404);
        }

        if ($invitation->state !== 'PENDING') {
            return response()->json(['error' => 'USED_INVITATION', 'message' => 'La invitación ya fue usada o revocada.'], 400);
        }

        if ($invitation->expires_at->isPast()) {
            return response()->json(['error' => 'EXPIRED_INVITATION', 'message' => 'La invitación ha expirado.'], 400);
        }

        // Generar un token de intercambio (exchange_token) de un solo uso para continuar el flujo
        $rawExchangeToken = Str::random(64);
        $exchangeTokenHash = hash('sha256', $rawExchangeToken);

        // Actualizar la invitación a estado PREPARED
        $invitation->update([
            'state' => 'PREPARED',
            'inspected_at' => now(),
            'exchange_token_hash' => $exchangeTokenHash,
            'exchange_expires_at' => now()->addMinutes(15), // 15 minutos para configurar clave y MFA
            'attempt_count' => $invitation->attempt_count + 1,
            'last_attempt_at' => now(),
        ]);

        // Generar la configuración de TOTP
        $google2fa = new Google2FA();
        $totpSecret = $google2fa->generateSecretKey();
        
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name', 'MisVales'),
            $invitation->user->email,
            $totpSecret
        );

        // Guardar el secreto TOTP temporalmente en caché vinculado al exchange_token
        Cache::put("totp_setup_{$exchangeTokenHash}", $totpSecret, now()->addMinutes(15));
        
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'INVITATION_INSPECTED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'AccountInvitation',
            'entity_id' => $invitation->id,
            'user_id' => $invitation->user_id,
        ]);

        return response()->json([
            'message' => 'Invitación válida. Proceda con la configuración de la cuenta.',
            'exchange_token' => $rawExchangeToken,
            'expires_in' => 15 * 60, // Segundos
            'user' => [
                'email' => $invitation->user->email,
                'name' => $invitation->user->name,
            ],
            'totp_setup' => [
                'secret' => $totpSecret,
                'qr_code_url' => $qrCodeUrl,
            ]
        ]);
    }

    /**
     * POST /api/v1/auth/invitations/setup
     * Fase 1: Valida MFA, contraseña (Argon2id) y genera códigos de recuperación.
     * La cuenta AÚN NO ESTÁ ACTIVA.
     */
    public function setup(Request $request)
    {
        $request->validate([
            'exchange_token' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()->symbols()],
            'totp_code' => 'required|string|size:6',
        ]);

        $exchangeTokenHash = hash('sha256', $request->exchange_token);

        $invitation = AccountInvitation::where('exchange_token_hash', $exchangeTokenHash)
            ->where('state', 'PREPARED')
            ->first();

        if (!$invitation || $invitation->exchange_expires_at->isPast()) {
            return response()->json(['error' => 'INVALID_INVITATION', 'message' => 'El token de intercambio es inválido o ha expirado.'], 400);
        }

        // Si ya configuró el MFA en un request previo a /setup, bloquemos el intento
        if ($invitation->mfa_setup_completed_at !== null) {
            return response()->json(['error' => 'USED_INVITATION', 'message' => 'La configuración ya fue procesada. Debe confirmar los códigos.'], 400);
        }

        // Recuperar secreto TOTP de la caché
        $totpSecret = Cache::get("totp_setup_{$exchangeTokenHash}");
        
        if (!$totpSecret) {
            return response()->json(['error' => 'EXPIRED_INVITATION', 'message' => 'La sesión de configuración MFA ha expirado. Vuelva a inspeccionar la invitación.'], 400);
        }

        // Validar el código TOTP protegiendo contra Replay Attacks
        $mfaService = new \App\Services\Auth\MfaService();
        $isValidTotp = $mfaService->verifyTotp($totpSecret, $request->totp_code, $invitation->user_id);

        if (!$isValidTotp) {
            // Aumentar contador de intentos en la invitación
            $invitation->increment('attempt_count');
            $invitation->update(['last_attempt_at' => now()]);
            return response()->json(['error' => 'INVALID_MFA', 'message' => 'El código de autenticador es incorrecto.'], 401);
        }

        // Generar 10 códigos de recuperación en texto plano (xxxx-xxxx)
        $rawRecoveryCodes = collect(range(1, 10))->map(function () {
            return strtolower(Str::random(4) . '-' . Str::random(4));
        });

        DB::transaction(function () use ($invitation, $request, $totpSecret, $rawRecoveryCodes) {
            $user = $invitation->user;

            // 1. Hashear contraseña con Argon2id. EL USUARIO SIGUE EN SU ESTADO ORIGINAL HASTA CONFIRMAR CÓDIGOS.
            $user->update([
                'password' => Hash::driver('argon2id')->make($request->password),
                'mfa_enrolled_at' => now(),
                'password_changed_at' => now(),
            ]);

            // 2. Registrar Credencial MFA encriptando el secreto
            MfaCredential::create([
                'user_id' => $user->id,
                'type' => 'TOTP',
                'secret_ciphertext' => Crypt::encryptString($totpSecret),
                'confirmed_at' => now(),
            ]);

            // 3. Registrar Códigos de Recuperación (Solo el Hash SHA-256)
            $batchId = Str::uuid();
            $now = now();
            
            $recoveryCodesData = $rawRecoveryCodes->map(function ($code, $index) use ($user, $batchId, $now) {
                return [
                    'id' => Str::uuid(),
                    'user_id' => $user->id,
                    'batch_id' => $batchId,
                    'code_hash' => hash('sha256', $code), // Solo guardamos el hash
                    'position' => $index + 1,
                    'generated_at' => $now,
                ];
            });

            MfaRecoveryCode::insert($recoveryCodesData->toArray());

            // 4. Marcar setup como completado, pero la invitación SIGUE PREPARED.
            $invitation->update([
                'mfa_setup_completed_at' => $now,
            ]);

            // 5. Limpiar Caché
            Cache::forget("totp_setup_{$request->exchange_token}");
        });

        return response()->json([
            'message' => 'Configuración de seguridad guardada exitosamente.',
            'recovery_codes' => $rawRecoveryCodes,
            'note' => 'GUARDE ESTOS CÓDIGOS EN UN LUGAR SEGURO. Solo se mostrarán esta vez. Llame a /complete para finalizar la activación.'
        ]);
    }

    /**
     * POST /api/v1/auth/invitations/complete
     * Fase 2: Confirma códigos de rescate, activa la cuenta y quema la invitación.
     */
    public function complete(Request $request)
    {
        $request->validate([
            'exchange_token' => 'required|string',
            'codes_safeguarded' => 'required|boolean|accepted',
        ]);

        $exchangeTokenHash = hash('sha256', $request->exchange_token);

        $invitation = AccountInvitation::where('exchange_token_hash', $exchangeTokenHash)
            ->where('state', 'PREPARED')
            ->whereNotNull('mfa_setup_completed_at')
            ->first();

        if (!$invitation || $invitation->exchange_expires_at->isPast()) {
            return response()->json(['error' => 'INVALID_INVITATION', 'message' => 'El token de intercambio es inválido, ha expirado, o no se ha completado el setup.'], 400);
        }

        DB::transaction(function () use ($invitation) {
            $user = $invitation->user;

            // 1. Activar cuenta finalmente
            $user->update([
                'state' => 'ACTIVE',
            ]);

            // 2. Quemar la invitación
            $invitation->update([
                'state' => 'CONSUMED',
                'consumed_at' => now(),
                'recovery_codes_confirmed_at' => now(),
                'exchange_token_hash' => null, // Invalidar explícitamente
            ]);
        });
        
        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'INVITATION_USED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'AccountInvitation',
            'entity_id' => $invitation->id,
            'user_id' => $invitation->user_id,
        ]);

        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'ACCOUNT_ACTIVATED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $invitation->user_id,
            'user_id' => $invitation->user_id,
        ]);

        return response()->json([
            'message' => 'Su cuenta ha sido activada exitosamente. Ahora puede iniciar sesión.'
        ]);
    }
}
