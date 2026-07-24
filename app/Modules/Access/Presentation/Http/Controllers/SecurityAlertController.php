<?php

namespace App\Modules\Access\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Application\Security\SecurityAlertService;
use App\Modules\Access\Infrastructure\Persistence\Models\SecurityAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SecurityAlertController extends Controller
{
    public function __construct(private readonly SecurityAlertService $alerts) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->alerts->visibleTo($user)->paginate(25),
        ]);
    }

    public function acknowledge(Request $request, SecurityAlert $alert): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->alerts->acknowledge($user, $alert)]);
    }

    public function requestAction(Request $request, SecurityAlert $alert): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->alerts->requestAction($user, $alert, $validated['reason']),
        ], 202);
    }
}
