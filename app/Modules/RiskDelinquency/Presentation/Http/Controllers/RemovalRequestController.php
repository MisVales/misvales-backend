<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\RiskDelinquency\Application\Queries\RiskQueryService;
use App\Modules\RiskDelinquency\Application\Services\DecideDelinquencyRemoval;
use App\Modules\RiskDelinquency\Application\Services\PrepareDelinquencyRemoval;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyRemovalRequest;
use App\Modules\RiskDelinquency\Presentation\Http\Requests\PrepareRemovalRequest;
use App\Modules\RiskDelinquency\Presentation\Http\Requests\RiskDecisionRequest;
use App\Modules\RiskDelinquency\Presentation\Http\Requests\RiskIndexRequest;
use App\Modules\RiskDelinquency\Presentation\Http\Resources\RemovalRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RemovalRequestController extends Controller
{
    public function index(RiskIndexRequest $request): AnonymousResourceCollection
    {
        return RemovalRequestResource::collection(
            app(RiskQueryService::class)->removalRequests($this->actor($request), $request->validated()),
        );
    }

    public function show(RiskIndexRequest $request, string $removalRequest): RemovalRequestResource
    {
        return new RemovalRequestResource(
            app(RiskQueryService::class)->removalRequest($this->actor($request), $removalRequest),
        );
    }

    public function prepare(PrepareRemovalRequest $request, string $distributor): JsonResponse
    {
        $target = User::query()->with('role')->where('public_id', $distributor)->firstOrFail();
        $removal = app(PrepareDelinquencyRemoval::class)->prepare(
            $this->actor($request),
            $target,
            (string) $request->header('Idempotency-Key', ''),
            $request->validated('reason'),
        );

        return $this->mutationResponse($request, $removal);
    }

    public function approve(RiskDecisionRequest $request, string $removalRequest): JsonResponse
    {
        $removal = app(DecideDelinquencyRemoval::class)->approve(
            $this->actor($request),
            $removalRequest,
            (string) $request->validated('reauthentication_token'),
            (string) $request->header('Idempotency-Key', ''),
            $request->validated('reason'),
        );

        return $this->mutationResponse($request, $removal);
    }

    public function reject(RiskDecisionRequest $request, string $removalRequest): JsonResponse
    {
        $removal = app(DecideDelinquencyRemoval::class)->reject(
            $this->actor($request),
            $removalRequest,
            (string) $request->validated('reauthentication_token'),
            (string) $request->header('Idempotency-Key', ''),
            $request->validated('reason'),
        );

        return $this->mutationResponse($request, $removal);
    }

    private function actor(RiskIndexRequest|RiskDecisionRequest|PrepareRemovalRequest $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $actor->loadMissing('role');

        return $actor;
    }

    private function mutationResponse(
        RiskDecisionRequest|PrepareRemovalRequest $request,
        DelinquencyRemovalRequest $removal,
    ): JsonResponse {
        return response()->json(
            ['data' => (new RemovalRequestResource($removal))->resolve($request)],
            $removal->getAttribute('_idempotency_replayed') === true ? 200 : 201,
        );
    }
}
