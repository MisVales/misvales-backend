<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Auth\LoginAttemptRateLimiter;
use App\Modules\Access\Infrastructure\Redis\MfaSessionManager;
use App\Modules\Access\Presentation\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class LoginController extends Controller
{
    public function __construct(
        private readonly LoginAttemptRateLimiter $rateLimiter,
        private readonly MfaSessionManager $mfaSessionManager
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $password = $request->validated('password');
        $application = $request->validated('application');
        $ip = $request->ip() ?? '0.0.0.0';
        $deviceId = $request->cookie('device_id') ?? 'unknown-device';

        // 1. Verificar si está bloqueado por Rate Limiting
        $this->rateLimiter->ensureCanAttemptLogin($email, $ip, $deviceId);

        // 2. Buscar al usuario
        /** @var User|null $user */
        $user = User::where('normalized_email', mb_strtolower($email))->first();

        // 3. Validar estado y contraseña
        if (!$user || !Hash::check($password, $user->password) || $user->state !== 'ACTIVE') {
            $this->rateLimiter->recordFailedLogin($email, $ip, $deviceId, $user);
            return response()->json(['message' => 'No fue posible iniciar sesión con la información proporcionada.'], 401);
        }

        // 4. Limpiar los intentos fallidos de 15 minutos (Mantiene historial 24h)
        $this->rateLimiter->clearLoginAttempts($email);

        // 5. Determinar métodos MFA permitidos
        $allowedFactors = \App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('state', 'ACTIVE')
            ->pluck('type')
            ->toArray();

        // Si no tiene factores configurados y es obligatorio, la política define el flujo (ej. forzar enrolamiento).
        // Por ahora, generamos la sesión transitoria.

        // 6. Generar Sesión MFA temporal (5 minutos)
        $mfaSession = $this->mfaSessionManager->createSession($user, $application, $ip, $deviceId, $allowedFactors);

        return response()->json([
            'message' => 'Credenciales válidas. Verificación de dos pasos requerida.',
            'data' => [
                'mfa_token' => $mfaSession['auth_token'],
                'expires_at' => $mfaSession['expires_at'],
                'allowed_factors' => $allowedFactors,
            ],
        ]);
    }
}
