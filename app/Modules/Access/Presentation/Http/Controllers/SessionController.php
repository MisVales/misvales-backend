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
}
