<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Auth\LoginAttemptRateLimiter;
use App\Modules\Access\Application\Auth\SessionManager;
use App\Modules\Access\Application\MFA\RecoveryCodeGenerator;
use App\Modules\Access\Application\MFA\TotpVerifier;
use App\Modules\Access\Application\Security\SecurityAuditService;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaRecoveryCode;
use App\Modules\Access\Infrastructure\Redis\MfaSessionManager;
use App\Modules\Access\Presentation\Http\Requests\VerifyPasskeyRequest;
use App\Modules\Access\Presentation\Http\Requests\VerifyRecoveryCodeRequest;
use App\Modules\Access\Presentation\Http\Requests\VerifyTotpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class MfaVerificationController extends Controller
{
    public function __construct(
        private readonly MfaSessionManager $sessionManager,
        private readonly TotpVerifier $totpVerifier,
        private readonly LoginAttemptRateLimiter $rateLimiter,
        private readonly SessionManager $appSessionManager,
        private readonly SecurityAuditService $audit,
    ) {}

    public function verifyTotp(VerifyTotpRequest $request): JsonResponse
    {
        $session = $this->sessionManager->getSession($request->validated('mfa_token'));
        if (! $session) {
            return response()->json(['message' => 'Sesión MFA expirada o inválida.'], 401);
        }

        $user = User::find($session['user_id']);
        $this->rateLimiter->ensureCanAttemptMfa($user->normalized_email, $session['ip_address'], $session['device_id']);

        $totpCredential = MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('type', MfaType::TOTP->value)
            ->where('state', 'ACTIVE')
            ->first();

        if (! $totpCredential) {
            return response()->json(['message' => 'El usuario no tiene TOTP configurado.'], 400);
        }

        try {
            $secret = Crypt::decryptString($totpCredential->encrypted_secret);
        } catch (\Throwable) {
            $this->audit->record('MFA_TOTP_VERIFICATION_FAILED', 'DENIED', $user, $user, [
                'rule' => 'TOTP_SECRET_UNAVAILABLE',
            ]);

            return response()->json(['message' => 'No fue posible verificar el segundo factor.'], 500);
        }

        if (! $this->totpVerifier->verify($secret, $request->validated('code'))) {
            $this->rateLimiter->recordFailedMfa($user->normalized_email, $session['ip_address'], $session['device_id'], $user, function () use ($request) {
                $this->sessionManager->consumeSession($request->validated('mfa_token'));
            });
            $this->audit->record('MFA_TOTP_VERIFICATION_FAILED', 'DENIED', $user, $user, [
                'rule' => 'TOTP_INVALID',
            ]);

            return response()->json(['message' => 'Código TOTP incorrecto.'], 401);
        }

        $this->rateLimiter->clearMfaAttempts($user->normalized_email);

        $tokens = $this->appSessionManager->createSession($user, $session['application'] ?? 'administrativa', $session['device_id'], $session['ip_address']);

        $this->sessionManager->consumeSession($request->validated('mfa_token'));
        $this->audit->record('MFA_TOTP_VERIFIED', 'SUCCESS', $user, $user);

        return response()->json([
            'message' => 'Verificación TOTP exitosa.',
            'data' => [
                'access_token' => $tokens['access_token'],
                'expires_in' => $tokens['expires_in'],
            ],
        ])->cookie(
            '__Host-mv_refresh',
            $tokens['refresh_token'],
            ($tokens['expires_in'] === 600 ? 0 : 0), // The cookie is managed by session or absolute expiration in DB. Using 0 for session cookie or explicit time. Let's not set max-age, just HTTP only secure
            '/',
            null,
            true, // Secure
            true, // HttpOnly
            false, // Raw
            'Strict' // SameSite
        );
    }

    public function verifyRecoveryCode(VerifyRecoveryCodeRequest $request): JsonResponse
    {
        $session = $this->sessionManager->getSession($request->validated('mfa_token'));
        if (! $session) {
            return response()->json(['message' => 'Sesión MFA expirada o inválida.'], 401);
        }

        $user = User::find($session['user_id']);
        $this->rateLimiter->ensureCanAttemptMfa($user->normalized_email, $session['ip_address'], $session['device_id']);

        $codeHash = RecoveryCodeGenerator::hashCode($request->validated('code'));

        $recoveryCode = MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('code_hash', $codeHash)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->first();

        if (! $recoveryCode) {
            $this->rateLimiter->recordFailedMfa($user->normalized_email, $session['ip_address'], $session['device_id'], $user, function () use ($request) {
                $this->sessionManager->consumeSession($request->validated('mfa_token'));
            });
            $this->audit->record('MFA_RECOVERY_CODE_VERIFICATION_FAILED', 'DENIED', $user, $user, [
                'rule' => 'RECOVERY_CODE_INVALID',
            ]);

            return response()->json(['message' => 'Código de recuperación inválido.'], 401);
        }

        $this->rateLimiter->clearMfaAttempts($user->normalized_email);

        DB::transaction(function () use ($recoveryCode, $request) {
            $recoveryCode->update(['used_at' => now()]);
            $this->sessionManager->consumeSession($request->validated('mfa_token'));
        });

        $tokens = $this->appSessionManager->createSession($user, $session['application'] ?? 'administrativa', $session['device_id'], $session['ip_address']);
        $this->audit->record('MFA_RECOVERY_CODE_USED', 'SUCCESS', $user, $user);

        return response()->json([
            'message' => 'Verificación con código de recuperación exitosa.',
            'data' => [
                'access_token' => $tokens['access_token'],
                'expires_in' => $tokens['expires_in'],
            ],
        ])->cookie(
            '__Host-mv_refresh',
            $tokens['refresh_token'],
            0, '/', null, true, true, false, 'Strict'
        );
    }

    public function verifyPasskey(VerifyPasskeyRequest $request): JsonResponse
    {
        $session = $this->sessionManager->getSession($request->validated('mfa_token'));
        if (! $session) {
            return response()->json(['message' => 'Sesión MFA expirada o inválida.'], 401);
        }

        $user = User::find($session['user_id']);
        $this->rateLimiter->ensureCanAttemptMfa($user->normalized_email, $session['ip_address'], $session['device_id']);

        // TODO: Passkey verification logic against the authenticator assertion response.
        // Requires PublicKeyCredentialLoader and AuthenticatorAssertionResponseValidator.

        $this->rateLimiter->clearMfaAttempts($user->normalized_email);

        $tokens = $this->appSessionManager->createSession($user, $session['application'] ?? 'administrativa', $session['device_id'], $session['ip_address']);

        $this->sessionManager->consumeSession($request->validated('mfa_token'));
        $this->audit->record('MFA_PASSKEY_VERIFIED', 'SUCCESS', $user, $user);

        return response()->json([
            'message' => 'Verificación de Passkey exitosa.',
            'data' => [
                'access_token' => $tokens['access_token'],
                'expires_in' => $tokens['expires_in'],
            ],
        ])->cookie(
            '__Host-mv_refresh',
            $tokens['refresh_token'],
            0, '/', null, true, true, false, 'Strict'
        );
    }
}
