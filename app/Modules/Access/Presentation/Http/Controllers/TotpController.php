<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\MFA\TotpService;
use App\Modules\Access\Presentation\Http\Requests\ConfirmTotpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class TotpController extends Controller
{
    public function __construct(private readonly TotpService $totpService) {}

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
            $this->totpService->confirm(
                $user, 
                $request->validated('secret'), 
                $request->validated('code')
            );

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
            $this->totpService->destroy($user);
            return response()->json([
                'message' => 'El autenticador TOTP ha sido retirado.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
