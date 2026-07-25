<?php

declare(strict_types=1);

namespace App\Modules\Credit\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Credit\Application\Services\CreditQueryService;
use App\Modules\Credit\Presentation\Http\Requests\CreditMovementIndexRequest;
use App\Modules\Credit\Presentation\Http\Resources\CreditLineMovementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CreditLineController extends Controller
{
    public function __construct(private readonly CreditQueryService $queries) {}

    public function show(Request $request, User $distributor): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->queries->summary($actor, $distributor)]);
    }

    public function movements(CreditMovementIndexRequest $request, User $distributor): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $paginator = $this->queries->movements($actor, $distributor, $request->validated());

        return response()->json(CreditLineMovementResource::collection($paginator)->response()->getData(true));
    }
}
