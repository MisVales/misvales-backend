<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Application\Auth\SessionManager;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SessionController extends Controller
{
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly TemporaryAuthorization $authorization,
    ) {}

    public function refresh(Request $request): JsonResponse
    {
        $application = $request->header('X-Application-Id');
        if (! $application) {
            return response()->json(['message' => 'X-Application-Id header missing.'], 400);
        }

        $refreshToken = $request->cookie('__Host-mv_refresh');
        if (! $refreshToken) {
            return response()->json(['message' => 'Refresh token missing.'], 401);
        }

        $tokens = $this->sessionManager->refreshSession($refreshToken, $application, $request->ip());

        return response()->json([
            'message' => 'Sesión renovada exitosamente.',
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

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token && $token->auth_session_id) {
            $session = AuthSession::find($token->auth_session_id);
            if ($session) {
                $this->sessionManager->revokeSession($session);
            }
        }

        return response()->json([
            'message' => 'Sesión cerrada exitosamente.',
        ])->withoutCookie('__Host-mv_refresh', '/', null, true);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $currentSessionId = $token?->auth_session_id;

        $sessions = $this->sessionManager->getUserSessions($user, $currentSessionId);

        return response()->json([
            'data' => $sessions,
        ]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $currentSessionId = $token?->auth_session_id;

        DB::transaction(function () use ($request, $user, $sessionId, $currentSessionId): void {
            $this->authorization->consume($user, $this->reauthToken($request), new AuthorizationBinding(
                action: CriticalAction::SESSION_REVOKE,
                resourceType: 'auth_sessions',
                resourceId: $sessionId,
                branchId: is_string($user->branch_id) ? $user->branch_id : null,
                parameters: [],
            ));
            $this->sessionManager->revokeOtherSession($user, $sessionId, (string) $currentSessionId);
        });

        return response()->json([
            'message' => 'Sesión revocada exitosamente.',
        ]);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $currentSessionId = $token?->auth_session_id;

        if (! $currentSessionId) {
            return response()->json(['message' => 'No se puede determinar la sesión actual.'], 400);
        }

        DB::transaction(function () use ($request, $user, $currentSessionId): void {
            $this->authorization->consume($user, $this->reauthToken($request), new AuthorizationBinding(
                action: CriticalAction::SESSION_REVOKE_OTHERS,
                resourceType: 'auth_sessions',
                resourceId: 'others',
                branchId: is_string($user->branch_id) ? $user->branch_id : null,
                parameters: [],
            ));
            $this->sessionManager->revokeAllOtherSessions($user, (string) $currentSessionId);
        });

        return response()->json([
            'message' => 'Todas las demás sesiones han sido revocadas.',
        ]);
    }

    private function reauthToken(Request $request): string
    {
        return (string) ($request->input('reauth_token') ?? $request->header('X-Reauthentication-Token', ''));
    }
}
