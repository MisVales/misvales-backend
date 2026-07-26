<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\RiskDelinquency\Application\Queries\GetDelinquencyReview;
use App\Modules\RiskDelinquency\Application\Queries\RiskQueryService;
use App\Modules\RiskDelinquency\Application\Services\ApplyDistributorDelinquency;
use App\Modules\RiskDelinquency\Presentation\Http\Requests\RiskDecisionRequest;
use App\Modules\RiskDelinquency\Presentation\Http\Requests\RiskIndexRequest;
use App\Modules\RiskDelinquency\Presentation\Http\Resources\DelinquencyDecisionResource;
use App\Modules\RiskDelinquency\Presentation\Http\Resources\RiskAlertResource;
use Illuminate\Http\JsonResponse;

final class RiskAlertController extends Controller
{
    public function show(RiskIndexRequest $request, string $alert): RiskAlertResource
    {
        return new RiskAlertResource(app(RiskQueryService::class)->alert($this->actor($request), $alert));
    }

    public function review(RiskIndexRequest $request, string $alert): JsonResponse
    {
        $review = app(GetDelinquencyReview::class)->get($this->actor($request), $alert);

        return response()->json([
            'data' => [
                'alert' => (new RiskAlertResource($review['alert']))->resolve($request),
                'financial_review' => $review['financial_review'],
            ],
        ]);
    }

    public function apply(RiskDecisionRequest $request, string $alert): JsonResponse
    {
        $decision = app(ApplyDistributorDelinquency::class)->apply(
            $this->actor($request),
            $alert,
            (string) $request->validated('reauthentication_token'),
            (string) $request->header('Idempotency-Key', ''),
            $request->validated('reason'),
        );

        return response()->json(
            ['data' => (new DelinquencyDecisionResource($decision))->resolve($request)],
            $decision->getAttribute('_idempotency_replayed') === true ? 200 : 201,
        );
    }

    private function actor(RiskIndexRequest|RiskDecisionRequest $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $actor->loadMissing('role');

        return $actor;
    }
}
