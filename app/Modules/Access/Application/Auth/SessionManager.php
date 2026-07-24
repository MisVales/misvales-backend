<?php

namespace App\Modules\Access\Application\Auth;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Application\Security\RiskCoordinator;
use App\Modules\Access\Application\Security\SecurityAuditService;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshTokenFamily;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SessionManager
{
    public function __construct(
        private readonly TemporaryAuthorization $authorizations,
        private readonly RiskCoordinator $risk,
        private readonly SecurityAuditService $audit,
    ) {}

    private const MAX_SESSIONS = 3;

    private const DURATIONS = [
        'administrativa' => ['absolute' => 8, 'inactivity' => 15],
        'tableta' => ['absolute' => 8, 'inactivity' => 15],
        'distribuidora' => ['absolute' => 24, 'inactivity' => 30],
    ];

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function createSession(User $user, string $application, ?string $deviceId, ?string $ipAddress): array
    {
        $this->ensureUnderSessionLimit($user);

        if (! isset(self::DURATIONS[$application])) {
            throw new \InvalidArgumentException('Aplicación no soportada.');
        }

        $durations = self::DURATIONS[$application];

        return DB::transaction(function () use ($user, $application, $deviceId, $ipAddress, $durations) {
            $session = AuthSession::create([
                'user_id' => $user->id,
                'application' => $application,
                'device_id' => $deviceId,
                'ip_address' => $ipAddress,
                'context_version' => $user->context_version,
                'last_activity_at' => now(),
                'expires_at' => now()->addHours($durations['absolute']),
                'state' => 'ACTIVE',
            ]);

            $plainRefreshToken = Str::random(40);
            $family = RefreshTokenFamily::query()->create([
                'auth_session_id' => $session->id,
                'application' => $application,
                'state' => SessionState::ACTIVE,
                'absolute_expires_at' => $session->expires_at,
            ]);
            RefreshToken::query()->create([
                'refresh_token_family_id' => $family->id,
                'auth_session_id' => $session->id,
                'token_hash' => hash('sha256', $plainRefreshToken),
                'state' => TokenState::ACTIVE,
                'issued_at' => now(),
                'expires_at' => $session->expires_at,
            ]);

            $token = $user->createToken(
                $application,
                ['*'],
                now()->addMinutes(10)
            );

            // Link token to session and context version
            $token->accessToken->forceFill([
                'auth_session_id' => $session->id,
                'context_version' => $user->context_version,
            ])->save();
            $this->audit->record('SESSION_CREATED', 'SUCCESS', $user, $user, [
                'session_id' => $session->id,
                'application' => $application,
                'ip_address' => $ipAddress,
                'device_id' => $deviceId,
                'resource_type' => 'auth_sessions',
                'resource_id' => (string) $session->id,
            ]);

            return [
                'access_token' => $token->plainTextToken,
                'refresh_token' => $plainRefreshToken,
                'expires_in' => 600, // 10 minutes
            ];
        });
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function refreshSession(string $plainRefreshToken, string $application, ?string $ipAddress): array
    {
        $tokenHash = hash('sha256', $plainRefreshToken);

        $refreshToken = RefreshToken::where('token_hash', $tokenHash)
            ->with(['session.user', 'family'])
            ->first();

        if (! $refreshToken) {
            $this->abortUnauthorized('Refresh token inválido.');
        }

        $session = $refreshToken->session;

        // Validar reutilización
        if ($refreshToken->state !== TokenState::ACTIVE
            || $refreshToken->used_at !== null
            || $refreshToken->revoked_at !== null
            || $refreshToken->family->state !== SessionState::ACTIVE
            || $session->state !== SessionState::ACTIVE->value) {
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
            $lockedToken = RefreshToken::query()->lockForUpdate()->findOrFail($refreshToken->id);
            $family = RefreshTokenFamily::query()->lockForUpdate()->findOrFail($refreshToken->refresh_token_family_id);
            if ($lockedToken->state !== TokenState::ACTIVE || $family->state !== SessionState::ACTIVE) {
                $this->revokeCompromisedFamily($lockedToken->load(['session.user', 'family']));
                $this->abortUnauthorized('Reutilización de token detectada. Sesión terminada por seguridad.');
            }

            $newPlainRefreshToken = Str::random(40);
            $lockedToken->forceFill([
                'state' => TokenState::REPLACED,
                'used_at' => now(),
                'replaced_at' => now(),
            ])->save();
            RefreshToken::query()->create([
                'refresh_token_family_id' => $family->id,
                'auth_session_id' => $session->id,
                'token_hash' => hash('sha256', $newPlainRefreshToken),
                'state' => TokenState::ACTIVE,
                'issued_at' => now(),
                'expires_at' => $family->absolute_expires_at,
            ]);

            // Invalidar access tokens anteriores de esta sesión
            $session->accessTokens()->delete();

            // Emitir nuevo access token
            $token = $session->user->createToken(
                $application,
                ['*'],
                now()->addMinutes(10)
            );
            $token->accessToken->forceFill([
                'auth_session_id' => $session->id,
                'context_version' => $session->user->context_version,
            ])->save();
            $this->audit->record('SESSION_REFRESHED', 'SUCCESS', $session->user, $session->user, [
                'session_id' => $session->id,
                'application' => $application,
                'ip_address' => $ipAddress,
                'resource_type' => 'auth_sessions',
                'resource_id' => (string) $session->id,
            ]);

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
        $this->risk->assessAndRespond(
            'REFRESH_TOKEN_REUSE_DETECTED',
            $token->session->user,
            $token->session,
            [
                'refresh_reuse' => true,
                'reason' => 'Se presentó nuevamente un refresh token ya consumido o revocado.',
            ],
        );
    }

    public function revokeSession(AuthSession $session): void
    {
        $this->authorizations->invalidateSession($session, 'SESSION_REVOKED');
        $session->update([
            'state' => 'REVOKED',
            'revoked_at' => now(),
        ]);
        $session->refreshTokens()
            ->where('state', TokenState::ACTIVE->value)
            ->update(['state' => TokenState::REVOKED->value, 'revoked_at' => now()]);
        RefreshTokenFamily::query()
            ->where('auth_session_id', $session->id)
            ->where('state', SessionState::ACTIVE->value)
            ->update(['state' => SessionState::REVOKED->value, 'revoked_at' => now()]);
        $session->accessTokens()->delete();
        $this->audit->record('SESSION_REVOKED', 'SUCCESS', $session->user, $session->user, [
            'session_id' => $session->id,
            'application' => $session->application,
            'resource_type' => 'auth_sessions',
            'resource_id' => (string) $session->id,
        ]);
    }

    /**
     * @return list<array{id: int, application: string, device_id: string|null, created_at: string, last_activity_at: string, ip_address: string|null, is_current: bool}>
     */
    public function getUserSessions(User $user, ?string $currentSessionId = null): array
    {
        $sessions = AuthSession::where('user_id', $user->id)
            ->where('state', 'ACTIVE')
            ->where('expires_at', '>', now())
            ->get();

        return $sessions->map(function ($session) use ($currentSessionId) {
            return [
                'id' => $session->id,
                'application' => $session->application,
                'device_id' => $session->device_id, // This would be approx device/browser in a real app if parsed from UA
                'created_at' => $session->created_at->toIso8601String(),
                'last_activity_at' => $session->last_activity_at->toIso8601String(),
                'ip_address' => $this->maskIpAddress($session->ip_address),
                'is_current' => $session->id == $currentSessionId,
            ];
        })->toArray();
    }

    private function maskIpAddress(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/(\d+\.\d+)\.\d+\.\d+/', '$1.***.***', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            if (count($parts) >= 4) {
                return implode(':', array_slice($parts, 0, 4)).':***:***';
            }

            return '***:***:***:***';
        }

        return '***.***.***.***';
    }

    public function revokeOtherSession(User $user, string $sessionIdToRevoke, string $currentSessionId): void
    {
        $session = AuthSession::where('user_id', $user->id)
            ->where('id', $sessionIdToRevoke)
            ->first();

        if (! $session) {
            throw new HttpResponseException(response()->json(['message' => 'Sesión no encontrada.'], 404));
        }

        if ((string) $session->id === $currentSessionId) {
            throw new HttpResponseException(response()->json(['message' => 'No puedes usar este método para cerrar la sesión actual.'], 400));
        }

        $this->revokeSession($session);
    }

    public function revokeAllOtherSessions(User $user, string $currentSessionId): void
    {
        $sessions = AuthSession::where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->where('state', 'ACTIVE')
            ->get();

        foreach ($sessions as $session) {
            $this->revokeSession($session);
        }
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
                    'active_sessions' => $activeSessions->pluck('id'),
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
