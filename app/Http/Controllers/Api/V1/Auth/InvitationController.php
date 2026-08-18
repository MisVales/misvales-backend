<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\EstadoDistribuidora;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Mail\ActivationInvitationMail;
use App\Models\AccountInvitation;
use App\Models\Distribuidora;
use App\Models\MfaCredential;
use App\Models\MfaRecoveryCode;
use App\Models\OutboxEvent;
use App\Services\Audit\SecurityAuditService;
use App\Services\Auth\MfaService;
use App\Services\Auth\WebAuthnService;
use App\Services\Distribuidora\ValidadorActivacionDistribuidora;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

        if (! $invitation) {
            throw new ApiException('INVALID_INVITATION', 'Invitación inválida o no encontrada.', 404);
        }

        if (! in_array($invitation->state, ['ACTIVE', 'PREPARED'])) {
            throw new ApiException('USED_INVITATION', 'La invitación ya fue usada o revocada.', 400);
        }

        if ($invitation->expires_at->isPast()) {
            throw new ApiException('EXPIRED_INVITATION', 'La invitación ha expirado.', 400);
        }

        // Generar un token de intercambio (exchange_token) de un solo uso para continuar el flujo
        $rawExchangeToken = Str::random(64);
        $exchangeTokenHash = hash('sha256', $rawExchangeToken);

        $isResuming = $invitation->state === 'PREPARED' && ! is_null($invitation->mfa_setup_completed_at);

        // Actualizar la invitación a estado PREPARED
        $invitation->update([
            'state' => 'PREPARED',
            'inspected_at' => now(),
            'exchange_token_hash' => $exchangeTokenHash,
            'exchange_expires_at' => now()->addMinutes(15),
            'attempt_count' => $invitation->attempt_count + 1,
            'last_attempt_at' => now(),
        ]);

        app(SecurityAuditService::class)->log($request, [
            'event_type' => $isResuming ? 'INVITATION_RESUMED' : 'INVITATION_INSPECTED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'AccountInvitation',
            'entity_id' => $invitation->id,
            'user_id' => $invitation->user_id,
        ]);

        $responsePayload = [
            'message' => $isResuming ? 'Continuando con la configuración de la cuenta.' : 'Invitación válida. Proceda con la configuración de la cuenta.',
            'exchange_token' => $rawExchangeToken,
            'expires_in' => 15 * 60,
            'step' => $isResuming ? 'passkey' : 'setup',
            'user' => [
                'email' => $invitation->user->email,
                'name' => $invitation->user->name,
                'roles' => $invitation->user->roleScopes()->with('role')->get()->pluck('role.code')->toArray(),
            ],
        ];

        if (! $isResuming) {
            // Generar la configuración de TOTP solo si es la primera vez
            $google2fa = new Google2FA;
            $totpSecret = $google2fa->generateSecretKey();

            $qrCodeUrl = $google2fa->getQRCodeUrl(
                config('app.name', 'MisVales'),
                $invitation->user->email,
                $totpSecret
            );

            // Guardar el secreto TOTP temporalmente en caché
            Cache::put("totp_setup_{$exchangeTokenHash}", $totpSecret, now()->addMinutes(15));

            $responsePayload['totp_setup'] = [
                'secret' => $totpSecret,
                'qr_code_url' => $qrCodeUrl,
            ];
        }

        return response()->json($responsePayload);
    }

    /**
     * POST /api/v1/auth/invitations/resend
     * Reenvía una nueva invitación de activación invalidando las anteriores.
     */
    public function resend(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $tokenHash = hash('sha256', $request->token);

        // Buscar la invitación por token hash (incluso si está expirada o no en pending, para identificar al usuario)
        $invitation = AccountInvitation::where('token_hash', $tokenHash)->first();

        if (! $invitation) {
            throw new ApiException('INVALID_INVITATION', 'El token proporcionado no existe.', 404);
        }

        $user = $invitation->user;

        if ($user->state !== 'PENDING_ACTIVATION') {
            throw new ApiException('USER_NOT_PENDING', 'La cuenta ya está activa o no es elegible para activación.', 400);
        }

        // Generar un nuevo token
        $plainToken = Str::random(60);
        $newTokenHash = hash('sha256', $plainToken);

        DB::transaction(function () use ($user, $newTokenHash) {
            // Limpiar invitaciones previas del usuario
            AccountInvitation::where('user_id', $user->id)->delete();

            // Crear la nueva invitación
            AccountInvitation::create([
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'token_hash' => $newTokenHash,
                'expires_at' => now()->addHours(48),
            ]);
        });

        // Enviar el correo con la nueva invitación
        Mail::to($user->email)->queue(new ActivationInvitationMail($user, $plainToken));

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'INVITATION_RESENT',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Se ha enviado una nueva invitación de activación a su correo electrónico.',
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
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            'totp_code' => 'required|string|size:6',
        ]);

        $exchangeTokenHash = hash('sha256', $request->exchange_token);

        $invitation = AccountInvitation::where('exchange_token_hash', $exchangeTokenHash)
            ->where('state', 'PREPARED')
            ->first();

        if (! $invitation || $invitation->exchange_expires_at->isPast()) {
            throw new ApiException('INVALID_INVITATION', 'El token de intercambio es inválido o ha expirado.', 400);
        }

        // Si ya configuró el MFA en un request previo a /setup, bloquemos el intento
        if ($invitation->mfa_setup_completed_at !== null) {
            throw new ApiException('USED_INVITATION', 'La configuración ya fue procesada. Debe confirmar los códigos.', 400);
        }

        // Recuperar secreto TOTP de la caché
        $totpSecret = Cache::get("totp_setup_{$exchangeTokenHash}");

        if (! $totpSecret) {
            throw new ApiException('EXPIRED_INVITATION', 'La sesión de configuración MFA ha expirado. Vuelva a inspeccionar la invitación.', 400);
        }

        // Validar el código TOTP protegiendo contra Replay Attacks
        $mfaService = new MfaService;
        $isValidTotp = $mfaService->verifyTotp($totpSecret, $request->totp_code, $invitation->user_id);

        if (! $isValidTotp) {
            // Aumentar contador de intentos en la invitación
            $invitation->increment('attempt_count');
            $invitation->update(['last_attempt_at' => now()]);

            throw new ApiException('INVALID_MFA', 'El código de autenticador es incorrecto.', 401);
        }

        // Generar 10 códigos de recuperación en texto plano (xxxx-xxxx)
        $rawRecoveryCodes = collect(range(1, 10))->map(function () {
            return strtolower(Str::random(4).'-'.Str::random(4));
        });

        DB::transaction(function () use ($invitation, $request, $totpSecret, $rawRecoveryCodes) {
            $user = $invitation->user;

            // 1. Asignar contraseña (el modelo User tiene el cast 'hashed' que la hasheará automáticamente). EL USUARIO SIGUE EN SU ESTADO ORIGINAL HASTA CONFIRMAR CÓDIGOS.
            $user->update([
                'password' => $request->password,
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
            'note' => 'GUARDE ESTOS CÓDIGOS EN UN LUGAR SEGURO. Solo se mostrarán esta vez. Llame a /complete para finalizar la activación.',
        ]);
    }

    /**
     * POST /api/v1/auth/invitations/passkey/setup
     * (Opcional) Fase 1.5: Generar opciones de Passkey
     */
    public function passkeySetup(Request $request, WebAuthnService $webAuthnService)
    {
        $request->validate([
            'exchange_token' => 'required|string',
        ]);

        $exchangeTokenHash = hash('sha256', $request->exchange_token);
        $invitation = AccountInvitation::where('exchange_token_hash', $exchangeTokenHash)
            ->where('state', 'PREPARED')
            ->whereNotNull('mfa_setup_completed_at')
            ->first();

        if (! $invitation || $invitation->exchange_expires_at->isPast()) {
            throw new ApiException('INVALID_INVITATION', 'Token inválido o expirado.', 400);
        }

        $options = $webAuthnService->generateRegistrationOptions($invitation->user);

        Cache::put("passkey_setup_{$exchangeTokenHash}", serialize($options), now()->addMinutes(10));

        return response($webAuthnService->serializeOptions($options))->header('Content-Type', 'application/json');
    }

    /**
     * POST /api/v1/auth/invitations/passkey/register
     * (Opcional) Fase 1.6: Registrar Passkey
     */
    public function passkeyRegister(Request $request, WebAuthnService $webAuthnService)
    {
        $request->validate([
            'exchange_token' => 'required|string',
            'clientDataJSON' => 'required|string',
            'attestationObject' => 'required|string',
        ]);

        $exchangeTokenHash = hash('sha256', $request->exchange_token);
        $invitation = AccountInvitation::where('exchange_token_hash', $exchangeTokenHash)
            ->where('state', 'PREPARED')
            ->whereNotNull('mfa_setup_completed_at')
            ->first();

        if (! $invitation || $invitation->exchange_expires_at->isPast()) {
            throw new ApiException('INVALID_INVITATION', 'Token inválido o expirado.', 400);
        }

        $cachedOptions = Cache::get("passkey_setup_{$exchangeTokenHash}");
        if (! $cachedOptions) {
            throw new ApiException('EXPIRED_PASSKEY_SESSION', 'Sesión expirada.', 400);
        }

        $options = unserialize($cachedOptions);

        try {
            $credentialData = $webAuthnService->verifyRegistration(
                $request->clientDataJSON,
                $request->attestationObject,
                $options
            );

            // Guardar el Passkey en MfaCredential
            MfaCredential::create([
                'user_id' => $invitation->user_id,
                'type' => 'PASSKEY',
                'credential_identifier' => base64_encode($credentialData->credentialId),
                'public_key' => base64_encode($credentialData->credentialPublicKey ?? ''), // Ensuring string format
                'aaguid' => (string) $credentialData->aaguid,
                'sign_count' => 0,
                'confirmed_at' => now(),
            ]);

            Cache::forget("passkey_setup_{$exchangeTokenHash}");

            return response()->json(['message' => 'Passkey registrado correctamente.']);
        } catch (\Exception $e) {
            throw new ApiException('PASSKEY_REGISTRATION_FAILED', 'Error al registrar el Passkey: '.$e->getMessage(), 400);
        }
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

        if (! $invitation || $invitation->exchange_expires_at->isPast()) {
            throw new ApiException('INVALID_INVITATION', 'El token de intercambio es inválido, ha expirado, o no se ha completado el setup.', 400);
        }

        $userRoles = $invitation->user->roleScopes()->with('role')->get()->pluck('role.code')->toArray();
        if (in_array('general_manager', $userRoles)) {
            $hasPasskey = MfaCredential::where('user_id', $invitation->user_id)->where('type', 'PASSKEY')->exists();
            if (! $hasPasskey) {
                return response()->json([
                    'error' => 'PASSKEY_REQUIRED',
                    'message' => 'Por política de seguridad corporativa, los Gerentes Generales deben registrar un Passkey de forma obligatoria para finalizar la activación.',
                ], 403);
            }
        }

        $distribuidoraActivada = DB::transaction(function () use ($invitation): ?Distribuidora {
            $user = $invitation->user;

            $distribuidora = Distribuidora::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($distribuidora !== null && $distribuidora->status === EstadoDistribuidora::PENDIENTE_ACTIVACION) {
                app(ValidadorActivacionDistribuidora::class)->validarComponentesObligatorios($distribuidora);
            }

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

            if ($distribuidora !== null && $distribuidora->status === EstadoDistribuidora::PENDIENTE_ACTIVACION) {
                $distribuidora->forceFill([
                    'status' => EstadoDistribuidora::ACTIVA,
                    'activated_at' => now(),
                    'activated_by' => $user->id,
                ])->save();

                OutboxEvent::create([
                    'event_type' => 'DISTRIBUTOR_ACCESS_ACTIVATED',
                    'payload' => [
                        'event_code' => 'EV-010',
                        'distributor_id' => $distribuidora->id,
                        'user_id' => $user->id,
                        'branch_id' => $distribuidora->branch_id,
                    ],
                    'status' => 'PENDING',
                ]);
            }

            return $distribuidora;
        });

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'INVITATION_USED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'AccountInvitation',
            'entity_id' => $invitation->id,
            'user_id' => $invitation->user_id,
        ]);

        if ($distribuidoraActivada !== null) {
            app(SecurityAuditService::class)->log($request, [
                'event_type' => 'DISTRIBUTOR_ACCESS_ACTIVATED',
                'severity' => 'INFO',
                'outcome' => 'SUCCESS',
                'entity_type' => 'Distributor',
                'entity_id' => $distribuidoraActivada->id,
                'user_id' => $invitation->user_id,
                'branch_id' => $distribuidoraActivada->branch_id,
            ]);
        }

        app(SecurityAuditService::class)->log($request, [
            'event_type' => 'ACCOUNT_ACTIVATED',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'entity_type' => 'User',
            'entity_id' => $invitation->user_id,
            'user_id' => $invitation->user_id,
        ]);

        return response()->json([
            'message' => 'Su cuenta ha sido activada exitosamente. Ahora puede iniciar sesión.',
        ]);
    }
}
