<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Application\Context\EffectiveContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ContextController extends Controller
{
    public function __construct(private readonly EffectiveContextBuilder $contextBuilder) {}

    public function getContext(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => [
                'code' => 'AUTHENTICATION_FAILED',
                'message' => 'No autorizado.',
                'correlationId' => (string) Str::uuid(),
            ]], 401);
        }

        // Recuperar información de la sesión desde el token o base de datos.
        // Sanctum no carga la sesión automáticamente, pero podemos obtenerla usando el currentAccessToken.
        $token = $user->currentAccessToken();

        $sessionData = [
            'id' => $token->auth_session_id ?? null,
            'created_at' => $token->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];

        $context = $this->contextBuilder->build($user, $sessionData);

        return response()->json([
            'data' => $context,
        ]);
    }
}
