<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Application\MFA\MfaRecoveryService;
use App\Modules\Access\Application\Security\SecurityAuditService;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RecoveryCodeController extends Controller
{
    public function __construct(
        private readonly MfaRecoveryService $recoveryService,
        private readonly TemporaryAuthorization $authorization,
        private readonly SecurityAuditService $audit,
    ) {}

    public function regenerate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $codes = DB::transaction(function () use ($request, $user): array {
            $this->authorization->consume(
                $user,
                (string) ($request->input('reauth_token') ?? $request->header('X-Reauthentication-Token', '')),
                new AuthorizationBinding(
                    action: CriticalAction::MFA_RECOVERY_CODES_REGENERATE,
                    resourceType: 'users',
                    resourceId: $user->public_id,
                    branchId: is_string($user->branch_id) ? $user->branch_id : null,
                    parameters: [],
                ),
            );

            $codes = $this->recoveryService->regenerate($user);
            $this->audit->record('MFA_RECOVERY_CODES_REGENERATED', 'SUCCESS', $user, $user, [
                'resource_type' => 'users',
                'resource_id' => $user->public_id,
            ]);

            return $codes;
        });

        return response()->json([
            'message' => 'Se han generado nuevos códigos de recuperación. Los anteriores ya no son válidos.',
            'data' => [
                'recovery_codes' => $codes,
            ],
        ]);
    }
}
