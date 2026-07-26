<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\RiskDelinquency\Application\Queries\RiskQueryService;
use App\Modules\RiskDelinquency\Presentation\Http\Requests\RiskIndexRequest;
use App\Modules\RiskDelinquency\Presentation\Http\Resources\RelationRiskEvaluationResource;
use App\Modules\RiskDelinquency\Presentation\Http\Resources\RiskAlertResource;
use App\Modules\RiskDelinquency\Presentation\Http\Resources\RiskProfileResource;
use App\Modules\RiskDelinquency\Presentation\Http\Resources\RiskSequenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RiskProfileController extends Controller
{
    public function __construct(private readonly RiskQueryService $queries) {}

    public function index(RiskIndexRequest $request): AnonymousResourceCollection
    {
        return RiskProfileResource::collection($this->queries->profiles($this->actor($request), $request->validated()));
    }

    public function show(RiskIndexRequest $request, string $distributor): RiskProfileResource
    {
        return new RiskProfileResource($this->queries->profile($this->actor($request), $this->distributor($distributor)));
    }

    public function evaluations(RiskIndexRequest $request, string $distributor): AnonymousResourceCollection
    {
        return RelationRiskEvaluationResource::collection($this->queries->evaluations(
            $this->actor($request),
            $this->distributor($distributor),
            (int) $request->validated('per_page', 25),
        ));
    }

    public function sequence(RiskIndexRequest $request, string $distributor): RiskSequenceResource|JsonResponse
    {
        $sequence = $this->queries->sequence($this->actor($request), $this->distributor($distributor));

        return $sequence === null
            ? response()->json(['data' => null])
            : new RiskSequenceResource($sequence);
    }

    public function alerts(RiskIndexRequest $request, string $distributor): AnonymousResourceCollection
    {
        return RiskAlertResource::collection($this->queries->alerts(
            $this->actor($request),
            $this->distributor($distributor),
            $request->validated(),
        ));
    }

    private function actor(RiskIndexRequest $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $actor->loadMissing('role');

        return $actor;
    }

    private function distributor(string $publicId): User
    {
        return User::query()->with('role')->where('public_id', $publicId)->firstOrFail();
    }
}
