<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Application\MFA\TotpService;
use App\Modules\Access\Application\Security\SecurityAuditService;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Presentation\Http\Requests\ConfirmTotpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TotpController extends Controller
{
    public function __construct(
        private readonly TotpService $totpService,
        private readonly TemporaryAuthorization $authorization,
        private readonly SecurityAuditService $audit,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $this->totpService->initiate($user);

        return response()->json([
            'data' => $data,
        ]);
    }

    public function confirm(ConfirmTotpRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            DB::transaction(function () use ($request, $user): void {
                $this->authorization->consume(
                    $user,
                    $request->string('reauth_token')->toString(),
                    $this->binding($user, CriticalAction::MFA_TOTP_ADD),
                );
                $this->totpService->confirm(
                    $user,
                    $request->validated('secret'),
                    $request->validated('code'),
                );
                $this->audit->record('MFA_TOTP_ENROLLED', 'SUCCESS', $user, $user, [
                    'resource_type' => 'users',
                    'resource_id' => $user->public_id,
                ]);
            });

            return response()->json([
                'message' => 'El autenticador TOTP ha sido registrado exitosamente.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            DB::transaction(function () use ($request, $user): void {
                $this->authorization->consume(
                    $user,
                    (string) ($request->input('reauth_token') ?? $request->header('X-Reauthentication-Token', '')),
                    $this->binding($user, CriticalAction::MFA_TOTP_REMOVE),
                );
                $this->totpService->destroy($user);
                $this->audit->record('MFA_TOTP_REMOVED', 'SUCCESS', $user, $user, [
                    'resource_type' => 'users',
                    'resource_id' => $user->public_id,
                ]);
            });

            return response()->json([
                'message' => 'El autenticador TOTP ha sido retirado.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function binding(User $user, CriticalAction $action): AuthorizationBinding
    {
        return new AuthorizationBinding(
            action: $action,
            resourceType: 'users',
            resourceId: $user->public_id,
            branchId: $user->branch_public_id,
            parameters: [],
        );
    }
}
