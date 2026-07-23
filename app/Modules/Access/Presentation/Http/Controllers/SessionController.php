<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Application\Auth\SessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    public function __construct(
        private readonly SessionManager $sessionManager
    ) {}

    public function refresh(Request $request): JsonResponse
    {
        $application = $request->header('X-Application-Id');
        if (!$application) {
            return response()->json(['message' => 'X-Application-Id header missing.'], 400);
        }

        $refreshToken = $request->cookie('__Host-mv_refresh');
        if (!$refreshToken) {
            return response()->json(['message' => 'Refresh token missing.'], 401);
        }

        $tokens = $this->sessionManager->refreshSession($refreshToken, $application, $request->ip());

        return response()->json([
            'message' => 'Sesión renovada exitosamente.',
            'data' => [
                'access_token' => $tokens['access_token'],
                'expires_in' => $tokens['expires_in']
            ]
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
            $session = \App\Modules\Access\Infrastructure\Persistence\Models\AuthSession::find($token->auth_session_id);
            if ($session) {
                $this->sessionManager->revokeSession($session);
            }
        }

        return response()->json([
            'message' => 'Sesión cerrada exitosamente.'
        ])->withoutCookie('__Host-mv_refresh', '/', null, true);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $currentSessionId = $token?->auth_session_id;

        $sessions = $this->sessionManager->getUserSessions($user, $currentSessionId);

        return response()->json([
            'data' => $sessions
        ]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        // TODO: Requires B10 Reauthentication Token (Validation should be checked here or in a middleware)
        // For B09, we assume it passes or we just execute it
        
        $user = $request->user();
        $token = $user->currentAccessToken();
        $currentSessionId = $token?->auth_session_id;

        $this->sessionManager->revokeOtherSession($user, $sessionId, $currentSessionId ?? '');

        return response()->json([
            'message' => 'Sesión revocada exitosamente.'
        ]);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        // TODO: Requires B10 Reauthentication Token
        
        $user = $request->user();
        $token = $user->currentAccessToken();
        $currentSessionId = $token?->auth_session_id;

        if (!$currentSessionId) {
            return response()->json(['message' => 'No se puede determinar la sesión actual.'], 400);
        }

        $this->sessionManager->revokeAllOtherSessions($user, $currentSessionId);

        return response()->json([
            'message' => 'Todas las demás sesiones han sido revocadas.'
        ]);
    }
}
