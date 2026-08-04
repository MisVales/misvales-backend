<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     */
    public function login(Request $request, \App\Services\Auth\ProgressiveLockoutService $lockoutService)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $ip = $request->ip();
        $email = trim(strtolower($request->email));
        $throttleKey = "login_attempts_{$ip}_{$email}";

        // 1. Bloqueo Progresivo Ciego (Punto 42)
        $lockoutSeconds = $lockoutService->checkLockout($ip, $email);
        if ($lockoutSeconds) {
            return response()->json(['error' => 'RATE_LIMIT_EXCEEDED', 'message' => "Demasiados intentos. Intente nuevamente en {$lockoutSeconds} segundos."], 429);
        }

        // 2. Rate Limiting General (Punto 41)
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json(['error' => 'RATE_LIMIT_EXCEEDED', 'message' => "Demasiados intentos. Intente nuevamente en {$seconds} segundos."], 429);
        }

        $user = User::where('normalized_email', $email)->first();

        // 3. Verificación de existencia y estado ciego
        if (!$user || $user->state !== 'ACTIVE') {
            RateLimiter::hit($throttleKey);
            $lockoutService->recordFailedAttempt($ip, $email);
            
            app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
                'event_type' => 'LOGIN_FAILED',
                'severity' => 'WARNING',
                'outcome' => 'FAILURE',
                'metadata' => ['email' => $email, 'reason' => 'invalid_user_or_inactive'],
            ]);

            // Mensaje Ciego: idéntico si existe o no existe
            return response()->json(['error' => 'INVALID_CREDENTIALS', 'message' => 'Credenciales inválidas.'], 401);
        }

        // 4. Verificación de contraseña
        if (!Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey);
            $lockoutService->recordFailedAttempt($ip, $email);
            
            app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
                'event_type' => 'LOGIN_FAILED',
                'severity' => 'WARNING',
                'outcome' => 'FAILURE',
                'user_id' => $user->id,
                'metadata' => ['email' => $email, 'reason' => 'invalid_password'],
            ]);
            
            return response()->json(['error' => 'INVALID_CREDENTIALS', 'message' => 'Credenciales inválidas.'], 401);
        }

        // Éxito: Limpiamos los contadores
        $lockoutService->clearLockout($ip, $email);
        RateLimiter::clear($throttleKey);

        $challengeToken = Str::random(64);
        $challengeHash = hash('sha256', $challengeToken);

        Cache::put("mfa_challenge_{$challengeHash}", $user->id, now()->addMinutes(5));

        return response()->json([
            'message' => 'Credenciales verificadas. Requiere segundo factor de autenticación (TOTP).',
            'mfa_challenge_token' => $challengeToken,
            'expires_in' => 300,
        ]);
    }

    /**
     * POST /api/v1/auth/mfa/totp/verify
     */
    public function verifyTotp(Request $request, MfaService $mfaService, SessionPolicyService $policyService)
    {
        $request->validate([
            'mfa_challenge_token' => 'required|string',
            'totp_code' => 'required|string|size:6',
        ]);

        $challengeHash = hash('sha256', $request->mfa_challenge_token);
        $userId = Cache::get("mfa_challenge_{$challengeHash}");

        if (!$userId) {
            return response()->json(['error' => 'EXPIRED_MFA_CHALLENGE', 'message' => 'El desafío MFA es inválido o ha expirado. Inicie sesión nuevamente.'], 400);
        }

        $user = User::find($userId);
        if (!$user) return response()->json(['error' => 'INVALID_SESSION', 'message' => 'Usuario no encontrado.'], 404);

        $mfaCredential = \App\Models\MfaCredential::where('user_id', $user->id)->where('type', 'TOTP')->first();
        if (!$mfaCredential) return response()->json(['error' => 'INVALID_MFA', 'message' => 'No hay configuración MFA activa para este usuario.'], 403);

        $secret = \Illuminate\Support\Facades\Crypt::decryptString($mfaCredential->secret_ciphertext);

        if (!$mfaService->verifyTotp($secret, $request->totp_code, $user->id)) {
            $user->failed_login_attempts += 1;
            if ($user->failed_login_attempts >= 5) {
                $user->locked_until = now()->addMinutes(15);
                Cache::forget("mfa_challenge_{$challengeHash}");
            }
            $user->save();

            app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
                'event_type' => 'MFA_FAILED',
                'severity' => 'WARNING',
                'outcome' => 'FAILURE',
                'user_id' => $user->id,
                'metadata' => ['mfa_type' => 'TOTP'],
            ]);

            return response()->json(['error' => 'INVALID_MFA', 'message' => 'El código de autenticador es incorrecto o ya fue utilizado.'], 401);
        }

        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'MFA_SUCCESS',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
            'metadata' => ['mfa_type' => 'TOTP'],
        ]);

        Cache::forget("mfa_challenge_{$challengeHash}");
        $user->last_login_at = now();
        $previousLoginIp = $user->last_login_ip;
        if ($previousLoginIp && $previousLoginIp !== $request->ip()) {
            \Illuminate\Support\Facades\Mail::to($user->email)->queue(
                new \App\Mail\Security\SecurityAlertMail(
                    $user,
                    'Nuevo inicio de sesión detectado',
                    'Hemos detectado un inicio de sesión en tu cuenta desde una dirección IP o ubicación no habitual.',
                    [
                        'ip' => $request->ip(),
                        'device' => app(\App\Services\Audit\SecurityAuditService::class)->parseDevice($request->userAgent()),
                        'time' => now()->toDateTimeString(),
                    ]
                )
            );
        }

        $user->last_login_ip = $request->ip();
        $user->failed_login_attempts = 0;
        $user->save();

        $mfaCredential->last_used_at = now();
        $mfaCredential->save();

        return $this->issueSessionTokens($user, $request, 'TOTP', $policyService);
    }

    /**
     * POST /api/v1/auth/mfa/recovery-code/verify
     */
    public function verifyRecoveryCode(Request $request, SessionPolicyService $policyService)
    {
        $request->validate([
            'mfa_challenge_token' => 'required|string',
            'recovery_code' => 'required|string',
        ]);

        $challengeHash = hash('sha256', $request->mfa_challenge_token);
        $userId = Cache::get("mfa_challenge_{$challengeHash}");

        if (!$userId) return response()->json(['error' => 'EXPIRED_MFA_CHALLENGE', 'message' => 'El desafío MFA es inválido o ha expirado. Inicie sesión nuevamente.'], 400);

        $user = User::find($userId);
        if (!$user) return response()->json(['error' => 'INVALID_SESSION', 'message' => 'Usuario no encontrado.'], 404);

        $codeHash = hash('sha256', $request->recovery_code);
        $recoveryCode = \App\Models\MfaRecoveryCode::where('user_id', $user->id)
            ->where('code_hash', $codeHash)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->first();

        if (!$recoveryCode) {
            $user->failed_login_attempts += 1;
            if ($user->failed_login_attempts >= 5) {
                $user->locked_until = now()->addMinutes(15);
                Cache::forget("mfa_challenge_{$challengeHash}");
            }
            $user->save();
            
            app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
                'event_type' => 'MFA_FAILED',
                'severity' => 'WARNING',
                'outcome' => 'FAILURE',
                'user_id' => $user->id,
                'metadata' => ['mfa_type' => 'RECOVERY_CODE'],
            ]);

            return response()->json(['error' => 'RECOVERY_CODE_USED', 'message' => 'El código de rescate es incorrecto o ya fue utilizado.'], 401);
        }

        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'event_type' => 'RECOVERY_CODE_USED',
            'severity' => 'WARNING',
            'outcome' => 'SUCCESS',
            'user_id' => $user->id,
        ]);

        $recoveryCode->used_at = now();
        $recoveryCode->save();

        Cache::forget("mfa_challenge_{$challengeHash}");
        $user->last_login_at = now();
        $previousLoginIp = $user->last_login_ip;
        if ($previousLoginIp && $previousLoginIp !== $request->ip()) {
            \Illuminate\Support\Facades\Mail::to($user->email)->queue(
                new \App\Mail\Security\SecurityAlertMail(
                    $user,
                    'Nuevo inicio de sesión detectado',
                    'Hemos detectado un inicio de sesión en tu cuenta desde una dirección IP o ubicación no habitual.',
                    [
                        'ip' => $request->ip(),
                        'device' => app(\App\Services\Audit\SecurityAuditService::class)->parseDevice($request->userAgent()),
                        'time' => now()->toDateTimeString(),
                    ]
                )
            );
        }
        
        $user->last_login_ip = $request->ip();
        $user->failed_login_attempts = 0;
        $user->save();

        return $this->issueSessionTokens($user, $request, 'RECOVERY_CODE', $policyService);
    }

    /**
     * POST /api/v1/auth/refresh
     * Utiliza el Refresh Token largo para emitir un nuevo Access Token corto.
     */
    public function refresh(Request $request, SessionPolicyService $policyService)
    {
        $request->validate(['refresh_token' => 'required|string']);

        $hash = hash('sha256', $request->refresh_token);
        $data = Cache::get("auth_refresh_{$hash}");

        if (!$data) {
            return response()->json(['error' => 'INVALID_SESSION', 'message' => 'Refresh token inválido o expirado.'], 401);
        }

        $user = User::find($data['user_id']);
        if (!$user || $user->state !== 'ACTIVE') {
            return response()->json(['error' => 'ACCOUNT_INACTIVE', 'message' => 'Usuario inactivo.'], 401);
        }

        // Buscar la sesión original
        $session = AuthSession::find($data['session_id']);
        if (!$session || $session->revoked_at || ($session->expires_at && $session->expires_at->isPast())) {
            Cache::forget("auth_refresh_{$hash}");
            return response()->json(['error' => 'INVALID_SESSION', 'message' => 'La sesión maestra ha expirado o fue revocada.'], 401);
        }

        // Validar inactividad en el refresco
        $policy = $policyService->getPolicyForUser($user);
        if ($session->last_activity_at && $session->last_activity_at->diffInMinutes(now()) > $policy['inactivity']) {
            $session->update(['revoked_at' => now(), 'revocation_reason' => 'INACTIVITY_TIMEOUT_ON_REFRESH']);
            Cache::forget("auth_refresh_{$hash}");
            return response()->json(['error' => 'INVALID_SESSION', 'message' => 'Sesión cerrada por inactividad.'], 401);
        }

        // Revocar token de Sanctum anterior (si sigue vivo)
        $user->tokens()->where('id', $data['access_token_id'])->delete();

        // Emitir nuevo Access Token
        $token = $user->createToken('auth_token_' . Str::random(10));
        $token->accessToken->expires_at = now()->addMinutes($policy['access_token']);
        $token->accessToken->save();

        // Actualizar el identificador de la sesión para que apunte al nuevo token
        $session->session_identifier_hash = hash('sha256', $token->plainTextToken);
        $session->last_activity_at = now();
        $session->save();

        // Actualizar el cache del refresh token para apuntar al nuevo access token id
        $data['access_token_id'] = $token->accessToken->id;
        Cache::put("auth_refresh_{$hash}", $data, now()->addMinutes($policy['refresh_token']));

        return response()->json([
            'message' => 'Sesión refrescada exitosamente.',
            'access_token' => $token->plainTextToken,
            'expires_in' => $policy['access_token'] * 60,
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $currentAccessToken = $user->currentAccessToken();

        if ($currentAccessToken) {
            $tokenHash = hash('sha256', $request->bearerToken());
            
            AuthSession::where('session_identifier_hash', $tokenHash)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $user->id,
                    'revocation_reason' => 'USER_LOGOUT'
                ]);

            $currentAccessToken->delete();
        }

        return response()->noContent();
    }

    /**
     * Private Helper: Emite Access y Refresh Tokens y registra la sesión
     */
    private function issueSessionTokens(User $user, Request $request, string $mfaMethod, SessionPolicyService $policyService)
    {
        $policy = $policyService->getPolicyForUser($user);

        // 1. Access Token (Corto)
        $token = $user->createToken('auth_token_' . Str::random(10));
        $token->accessToken->expires_at = now()->addMinutes($policy['access_token']);
        $token->accessToken->save();

        // 2. Registro de Sesión Maestra (Larga)
        $session = AuthSession::create([
            'user_id' => $user->id,
            'session_identifier_hash' => hash('sha256', $token->plainTextToken),
            'authentication_method' => 'PASSWORD',
            'mfa_method' => $mfaMethod,
            'mfa_verified_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_name' => $this->parseDeviceName($request->userAgent()),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes($policy['absolute']),
        ]);

        app(\App\Services\Audit\SecurityAuditService::class)->log($request, [
            'user_id' => $user->id,
            'actor_user_id' => $user->id,
            'auth_session_id' => $session->id,
            'event_type' => 'LOGIN_SUCCESS',
            'severity' => 'INFO',
            'outcome' => 'SUCCESS',
            'metadata' => ['mfa_method' => $mfaMethod],
        ]);

        // 3. Refresh Token (Largo en Redis)
        $rawRefreshToken = Str::random(80);
        $refreshHash = hash('sha256', $rawRefreshToken);

        Cache::put("auth_refresh_{$refreshHash}", [
            'user_id' => $user->id,
            'session_id' => $session->id,
            'access_token_id' => $token->accessToken->id,
        ], now()->addMinutes($policy['refresh_token']));

        return response()->json([
            'message' => 'Autenticación exitosa.',
            'access_token' => $token->plainTextToken,
            'refresh_token' => $rawRefreshToken,
            'expires_in' => $policy['access_token'] * 60, // En segundos
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]
        ]);
    }

    private function parseDeviceName(?string $userAgent): string
    {
        if (!$userAgent) return 'Unknown Device';
        if (str_contains($userAgent, 'Windows')) return 'Windows PC';
        if (str_contains($userAgent, 'Mac OS')) return 'Mac';
        if (str_contains($userAgent, 'Linux')) return 'Linux PC';
        if (str_contains($userAgent, 'iPhone')) return 'iPhone';
        if (str_contains($userAgent, 'Android')) return 'Android Device';
        return 'Other Device';
    }
}
