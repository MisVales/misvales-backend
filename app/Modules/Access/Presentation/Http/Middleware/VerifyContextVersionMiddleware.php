<?php

namespace App\Modules\Access\Presentation\Http\Middleware;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VerifyContextVersionMiddleware
{
    public function __construct(private readonly TemporaryAuthorization $authorizations) {}

    public function handle(Request $request, Closure $next): mixed
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        $tokenContextVersion = data_get($token, 'context_version');
        if ($tokenContextVersion !== null && (int) $tokenContextVersion !== $user->context_version) {
            $sessionId = data_get($token, 'auth_session_id');
            $session = is_numeric($sessionId) ? AuthSession::query()->find((int) $sessionId) : null;
            if ($session !== null) {
                $this->authorizations->invalidateSession($session, 'CONTEXT_CHANGED');
            }

            return response()->json(['error' => [
                'code' => 'CONTEXT_CHANGED',
                'message' => 'El contexto ha cambiado. La sesión fue revocada.',
                'correlationId' => (string) Str::uuid(),
            ]], 401);
        }

        return $next($request);
    }
}
