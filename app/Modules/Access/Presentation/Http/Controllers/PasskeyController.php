<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\MFA\PasskeyService;
use App\Modules\Access\Presentation\Http\Requests\StorePasskeyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

final class PasskeyController extends Controller
{
    public function __construct(private readonly PasskeyService $passkeyService) {}

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
            $this->passkeyService->register(
                $user, 
                $request->validated('clientDataJSON'), 
                $request->validated('attestationObject')
            );

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
            $this->passkeyService->destroy($user, $credentialId);
            return response()->json([
                'message' => 'Passkey retirado exitosamente.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
