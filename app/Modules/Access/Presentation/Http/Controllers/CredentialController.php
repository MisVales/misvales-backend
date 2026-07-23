<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Authentication\CredentialLifecycleService;
use App\Modules\Access\Presentation\Http\Requests\ChangePasswordRequest;
use App\Modules\Access\Presentation\Http\Requests\CompleteInvitationRequest;
use App\Modules\Access\Presentation\Http\Requests\CompletePasswordRecoveryRequest;
use App\Modules\Access\Presentation\Http\Requests\InspectInvitationRequest;
use App\Modules\Access\Presentation\Http\Requests\PasswordRecoveryRequest;
use Illuminate\Http\JsonResponse;

final class CredentialController extends Controller
{
    public function __construct(private readonly CredentialLifecycleService $credentials) {}

    public function inspect(InspectInvitationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->credentials->inspectInvitation($request->string('token')->toString())]);
    }

    public function completeInvitation(CompleteInvitationRequest $request): JsonResponse
    {
        /** @var array<string, mixed>|null $mfa */
        $mfa = $request->validated('mfa');
        $result = $this->credentials->completeInvitation(
            $request->string('exchange_token')->toString(),
            $request->validated('password'),
            $mfa,
            $request->boolean('recovery_codes_confirmed'),
        );

        return response()->json(['data' => $result]);
    }

    public function requestRecovery(PasswordRecoveryRequest $request): JsonResponse
    {
        return response()->json(['message' => $this->credentials->requestPasswordRecovery($request->string('email')->toString())], 202);
    }

    public function completeRecovery(CompletePasswordRecoveryRequest $request): JsonResponse
    {
        /** @var array<string, mixed>|null $mfa */
        $mfa = $request->validated('mfa');
        $result = $this->credentials->completeRecovery(
            $request->string('token')->toString(),
            $request->string('password')->toString(),
            $request->string('factor_type')->toString(),
            $request->string('factor_value')->toString(),
            $mfa,
        );

        return response()->json([
            'message' => 'Las credenciales fueron actualizadas. Debe iniciar sesión nuevamente.',
            'data' => $result,
        ]);
    }

    public function change(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->credentials->changePassword($user, $request->string('password')->toString(), $request->string('reauth_token')->toString());

        return response()->json(['message' => 'La contraseña fue actualizada. Debe iniciar sesión nuevamente.']);
    }
}
