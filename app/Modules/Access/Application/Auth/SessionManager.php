<?php

namespace App\Modules\Access\Application\Auth;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

final class SessionManager
{
    private const MAX_SESSIONS = 3;

    private const DURATIONS = [
        'administrativa' => ['absolute' => 8, 'inactivity' => 15],
        'tableta'        => ['absolute' => 8, 'inactivity' => 15],
        'distribuidora'  => ['absolute' => 24, 'inactivity' => 30],
    ];

    public function createSession(User $user, string $application, ?string $deviceId, ?string $ipAddress): array
    {
        $this->ensureUnderSessionLimit($user);

        if (!isset(self::DURATIONS[$application])) {
            throw new \InvalidArgumentException("Aplicación no soportada.");
        }

        $durations = self::DURATIONS[$application];

        return DB::transaction(function () use ($user, $application, $deviceId, $ipAddress, $durations) {
            $session = AuthSession::create([
                'user_id' => $user->id,
                'application' => $application,
                'device_id' => $deviceId,
                'ip_address' => $ipAddress,
                'context_version' => 1,
                'last_activity_at' => now(),
                'expires_at' => now()->addHours($durations['absolute']),
                'state' => 'ACTIVE',
            ]);

            $plainRefreshToken = Str::random(40);
            
            $refreshToken = RefreshToken::create([
                'auth_session_id' => $session->id,
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $plainRefreshToken),
                'family_id' => Str::uuid(),
                'expires_at' => $session->expires_at,
            ]);

            $token = $user->createToken(
                $application, 
                ['*'], 
                now()->addMinutes(10)
            );

            // Link token to session
            $token->accessToken->forceFill(['auth_session_id' => $session->id])->save();

            return [
                'access_token' => $token->plainTextToken,
                'refresh_token' => $plainRefreshToken,
                'expires_in' => 600, // 10 minutes
            ];
        });
    }

    public function refreshSession(string $plainRefreshToken, string $application, ?string $ipAddress): array
    {
        $tokenHash = hash('sha256', $plainRefreshToken);

        $refreshToken = RefreshToken::where('token_hash', $tokenHash)
            ->with(['session', 'user'])
            ->first();

        if (!$refreshToken) {
            $this->abortUnauthorized('Refresh token inválido.');
        }

        $session = $refreshToken->session;

        // Validar reutilización
        if ($refreshToken->used_at !== null || $refreshToken->revoked_at !== null || $session->state !== 'ACTIVE') {
            $this->revokeCompromisedFamily($refreshToken);
            $this->abortUnauthorized('Reutilización de token detectada. Sesión terminada por seguridad.');
        }

        // Validar expiración absoluta
        if (now()->greaterThanOrEqualTo($refreshToken->expires_at)) {
            $this->revokeSession($session);
            $this->abortUnauthorized('Sesión expirada absolutamente.');
        }

        // Validar aplicación
        if ($session->application !== $application) {
            $this->abortUnauthorized('Aplicación no coincide con la sesión original.');
        }

        // Validar inactividad
        $durations = self::DURATIONS[$application];
        $inactivityLimit = (clone $session->last_activity_at)->addMinutes($durations['inactivity']);
        if (now()->greaterThanOrEqualTo($inactivityLimit)) {
            $this->revokeSession($session);
            $this->abortUnauthorized('Sesión expirada por inactividad.');
        }

        // Validar contexto
        // TODO: Validate context_version match if implemented

        return DB::transaction(function () use ($refreshToken, $session, $application, $ipAddress) {
            // Marcar usado
            $refreshToken->update(['used_at' => now()]);

            // Crear nuevo refresh
            $newPlainRefreshToken = Str::random(40);
            RefreshToken::create([
                'auth_session_id' => $session->id,
                'user_id' => $session->user_id,
                'token_hash' => hash('sha256', $newPlainRefreshToken),
                'family_id' => $refreshToken->family_id,
                'expires_at' => $session->expires_at, // Vigencia absoluta original
            ]);

            // Invalidar access tokens anteriores de esta sesión
            $session->accessTokens()->delete();

            // Emitir nuevo access token
            $token = $session->user->createToken(
                $application, 
                ['*'], 
                now()->addMinutes(10)
            );
            $token->accessToken->forceFill(['auth_session_id' => $session->id])->save();

            // Rotar inactividad (renovación silenciosa NO debería actualizar actividad real según spec, 
            // pero si la llamada es legitima puede actualizar. El spec dice: "La renovación silenciosa no actualiza actividad.")
            // Así que NO actualizamos last_activity_at aquí.

            return [
                'access_token' => $token->plainTextToken,
                'refresh_token' => $newPlainRefreshToken,
                'expires_in' => 600,
            ];
        });
    }

    private function revokeCompromisedFamily(RefreshToken $token): void
    {
        DB::transaction(function () use ($token) {
            $session = $token->session;
            if ($session) {
                $this->revokeSession($session);
            }
            // Registrar incidente
            // TODO: Log security event (Block B08)
            /*
            SecurityEvent::create([
                'user_id' => $tokenRecord->user_id,
                'event_type' => 'TOKEN_REUSE_DETECTED',
                'ip_address' => request()->ip(),
                'device_id' => $session->device_id ?? 'unknown',
                'severity' => 'HIGH',
                'metadata' => ['family_id' => $tokenRecord->family_id]
            ]);
            */
        });
    }

    public function revokeSession(AuthSession $session): void
    {
        $session->update([
            'state' => 'REVOKED',
            'revoked_at' => now()
        ]);
        $session->refreshTokens()->update(['revoked_at' => now()]);
        $session->accessTokens()->delete();
    }

    private function ensureUnderSessionLimit(User $user): void
    {
        $activeSessions = AuthSession::where('user_id', $user->id)
            ->where('state', 'ACTIVE')
            ->where('expires_at', '>', now())
            ->get();

        if ($activeSessions->count() >= self::MAX_SESSIONS) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Límite de sesiones alcanzado.',
                    'active_sessions' => $activeSessions->pluck('id')
                ], 409)
            );
        }
    }

    private function abortUnauthorized(string $message): void
    {
        throw new HttpResponseException(
            response()->json(['message' => $message], 401)
        );
    }
}
