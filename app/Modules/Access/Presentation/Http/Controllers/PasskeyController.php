<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Application\MFA\PasskeyService;
use App\Modules\Access\Application\Security\SecurityAuditService;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Presentation\Http\Requests\StorePasskeyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

final class PasskeyController extends Controller
{
    public function __construct(
        private readonly PasskeyService $passkeyService,
        private readonly TemporaryAuthorization $authorization,
        private readonly SecurityAuditService $audit,
    ) {}

    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $options = $this->passkeyService->generateOptions($user);
        $attestationManager = AttestationStatementSupportManager::create();
        $serializer = (new WebauthnSerializerFactory($attestationManager))->create();

        return response()->json([
            'data' => json_decode($serializer->serialize($options, 'json'), true),
        ]);
    }

    public function store(StorePasskeyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            DB::transaction(function () use ($request, $user): void {
                $this->authorization->consume(
                    $user,
                    $request->string('reauth_token')->toString(),
                    $this->binding($user, CriticalAction::MFA_PASSKEY_ADD, $user->public_id),
                );
                $this->passkeyService->register(
                    $user,
                    $request->validated('clientDataJSON'),
                    $request->validated('attestationObject'),
                );
                $this->audit->record('MFA_PASSKEY_ENROLLED', 'SUCCESS', $user, $user, [
                    'resource_type' => 'users',
                    'resource_id' => $user->public_id,
                ]);
            });

            return response()->json([
                'message' => 'Passkey registrado exitosamente.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $credentialId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            DB::transaction(function () use ($request, $user, $credentialId): void {
                $this->authorization->consume(
                    $user,
                    (string) ($request->input('reauth_token') ?? $request->header('X-Reauthentication-Token', '')),
                    $this->binding($user, CriticalAction::MFA_PASSKEY_REMOVE, (string) $credentialId),
                );
                $this->passkeyService->destroy($user, $credentialId);
                $this->audit->record('MFA_PASSKEY_REMOVED', 'SUCCESS', $user, $user, [
                    'resource_type' => 'mfa_credentials',
                    'resource_id' => (string) $credentialId,
                ]);
            });

            return response()->json([
                'message' => 'Passkey retirado exitosamente.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function binding(User $user, CriticalAction $action, string $resourceId): AuthorizationBinding
    {
        return new AuthorizationBinding(
            action: $action,
            resourceType: 'mfa_credentials',
            resourceId: $resourceId,
            branchId: is_string($user->branch_id) ? $user->branch_id : null,
            parameters: [],
        );
    }
}
