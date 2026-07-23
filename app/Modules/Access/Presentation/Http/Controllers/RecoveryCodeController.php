<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\MFA\MfaRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecoveryCodeController extends Controller
{
    public function __construct(private readonly MfaRecoveryService $recoveryService) {}

    public function regenerate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $codes = $this->recoveryService->regenerate($user);

        return response()->json([
            'message' => 'Se han generado nuevos códigos de recuperación. Los anteriores ya no son válidos.',
            'data' => [
                'recovery_codes' => $codes,
            ],
        ]);
    }
}
