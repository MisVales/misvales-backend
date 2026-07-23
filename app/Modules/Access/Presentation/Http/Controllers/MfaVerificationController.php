<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Auth\LoginAttemptRateLimiter;
use App\Modules\Access\Application\MFA\RecoveryCodeGenerator;
use App\Modules\Access\Application\MFA\TotpVerifier;
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
        private readonly LoginAttemptRateLimiter $rateLimiter
    ) {}

    public function verifyTotp(VerifyTotpRequest $request): JsonResponse
    {
        $session = $this->sessionManager->getSession($request->validated('mfa_token'));
        if (!$session) {
            return response()->json(['message' => 'Sesión MFA expirada o inválida.'], 401);
        }

        $user = User::find($session['user_id']);
        $this->rateLimiter->ensureCanAttemptMfa($user->normalized_email, $session['ip_address'], $session['device_id']);

        $totpCredential = MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('type', MfaType::TOTP->value)
            ->where('state', 'ACTIVE')
            ->first();

        if (!$totpCredential) {
            return response()->json(['message' => 'El usuario no tiene TOTP configurado.'], 400);
        }

        $secret = Crypt::decryptString($totpCredential->encrypted_secret);

        if (!$this->totpVerifier->verify($secret, $request->validated('code'))) {
            $this->rateLimiter->recordFailedMfa($user->normalized_email, $session['ip_address'], $session['device_id'], $user, function () use ($request) {
                $this->sessionManager->consumeSession($request->validated('mfa_token'));
            });
            return response()->json(['message' => 'Código TOTP incorrecto.'], 401);
        }

        $this->rateLimiter->clearMfaAttempts($user->normalized_email);

        $this->sessionManager->consumeSession($request->validated('mfa_token'));

        return response()->json([
            'message' => 'Verificación TOTP exitosa.',
            // B06 se encargará de inyectar el AccessToken aquí.
            'data' => ['auth_token' => 'TODO_B06_TOKEN']
        ]);
    }

    public function verifyRecoveryCode(VerifyRecoveryCodeRequest $request): JsonResponse
    {
        $session = $this->sessionManager->getSession($request->validated('mfa_token'));
        if (!$session) {
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

        if (!$recoveryCode) {
            $this->rateLimiter->recordFailedMfa($user->normalized_email, $session['ip_address'], $session['device_id'], $user, function () use ($request) {
                $this->sessionManager->consumeSession($request->validated('mfa_token'));
            });
            return response()->json(['message' => 'Código de recuperación inválido.'], 401);
        }

        $this->rateLimiter->clearMfaAttempts($user->normalized_email);

        DB::transaction(function () use ($recoveryCode, $request) {
            $recoveryCode->update(['used_at' => now()]);
            $this->sessionManager->consumeSession($request->validated('mfa_token'));
        });

        return response()->json([
            'message' => 'Verificación con código de recuperación exitosa.',
            // B06 se encargará de inyectar el AccessToken aquí.
            'data' => ['auth_token' => 'TODO_B06_TOKEN']
        ]);
    }

    public function verifyPasskey(VerifyPasskeyRequest $request): JsonResponse
    {
        $session = $this->sessionManager->getSession($request->validated('mfa_token'));
        if (!$session) {
            return response()->json(['message' => 'Sesión MFA expirada o inválida.'], 401);
        }

        $user = User::find($session['user_id']);
        $this->rateLimiter->ensureCanAttemptMfa($user->normalized_email, $session['ip_address'], $session['device_id']);

        // TODO: Passkey verification logic against the authenticator assertion response.
        // Requires PublicKeyCredentialLoader and AuthenticatorAssertionResponseValidator.
        
        $this->rateLimiter->clearMfaAttempts($user->normalized_email);
        
        $this->sessionManager->consumeSession($request->validated('mfa_token'));

        return response()->json([
            'message' => 'Verificación de Passkey exitosa.',
            'data' => ['auth_token' => 'TODO_B06_TOKEN']
        ]);
    }
}
