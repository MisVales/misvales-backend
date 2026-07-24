<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Authorization\ReauthenticationService;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\ReauthenticationMethod;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Presentation\Http\Requests\ReauthenticateRequest;
use Illuminate\Http\JsonResponse;

final class ReauthenticationController extends Controller
{
    public function __construct(private readonly ReauthenticationService $reauthentication) {}

    public function store(ReauthenticateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = $this->currentSession($user);
        $binding = new AuthorizationBinding(
            action: CriticalAction::from($request->string('action')->toString()),
            resourceType: $request->filled('resource_type') ? $request->string('resource_type')->toString() : null,
            resourceId: $request->filled('resource_id') ? $request->string('resource_id')->toString() : null,
            branchId: $request->filled('branch_id') ? $request->string('branch_id')->toString() : null,
            parameters: $request->validated('parameters', []),
            reason: $request->filled('reason') ? $request->string('reason')->toString() : null,
        );
        $method = ReauthenticationMethod::from($request->string('method')->toString());

        if ($method === ReauthenticationMethod::PASSKEY && ! $request->filled('assertion')) {
            return response()->json([
                'data' => $this->reauthentication->beginPasskey($user, $session, $binding),
            ], 202);
        }

        $result = $method === ReauthenticationMethod::PASSKEY
            ? $this->reauthentication->reauthenticateWithPasskey(
                $user,
                $session,
                $binding,
                $request->string('challenge_id')->toString(),
                $request->validated('assertion', []),
            )
            : $this->reauthentication->reauthenticateWithPasswordTotp(
                $user,
                $session,
                $binding,
                $request->string('password')->toString(),
                $request->string('totp_code')->toString(),
            );

        return response()->json(['data' => $result]);
    }

    private function currentSession(User $user): AuthSession
    {
        $token = $user->currentAccessToken();
        $sessionId = data_get($token, 'auth_session_id');
        $session = is_int($sessionId) ? AuthSession::query()->find($sessionId) : null;

        if ($session === null) {
            throw new AccessRuleViolation(
                'La sesión autenticada no permite reautenticación.',
                401,
                'REAUTHENTICATION_REQUIRED',
            );
        }

        return $session;
    }
}
