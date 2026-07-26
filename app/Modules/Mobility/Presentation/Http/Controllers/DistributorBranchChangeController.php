<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Mobility\Application\Queries\MobilityQueryService;
use App\Modules\Mobility\Application\Services\MobilityWorkflowService;
use App\Modules\Mobility\Presentation\Http\Requests\BranchClientDestinationsRequest;
use App\Modules\Mobility\Presentation\Http\Requests\CreateBranchChangeRequest;
use App\Modules\Mobility\Presentation\Http\Requests\DestinationCoordinatorRequest;
use App\Modules\Mobility\Presentation\Http\Requests\MobilityDecisionRequest;
use App\Modules\Mobility\Presentation\Http\Requests\MobilityIndexRequest;
use App\Modules\Mobility\Presentation\Http\Resources\MobilityProcessResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

final class DistributorBranchChangeController extends Controller
{
    public function __construct(
        private readonly MobilityWorkflowService $workflow,
        private readonly MobilityQueryService $queries,
    ) {}

    public function index(MobilityIndexRequest $request): AnonymousResourceCollection
    {
        return MobilityProcessResource::collection($this->queries->branchChanges($this->actor($request), $request->validated()));
    }

    public function store(CreateBranchChangeRequest $request): JsonResponse
    {
        $branch = Branch::query()->where('public_id', $request->validated('destination_branch_id'))->firstOrFail();
        $input = $request->safe()->except(['idempotency_key', 'destination_branch_id']);
        $input['destination_branch_id'] = $branch->id;
        $model = $this->workflow->createBranchChange(
            $this->actor($request), $input, (string) $request->validated('idempotency_key'), $this->correlation($request),
        );

        return $this->response($request, $model, 201);
    }

    public function show(MobilityIndexRequest $request, string $change): MobilityProcessResource
    {
        return new MobilityProcessResource($this->queries->branchChange($this->actor($request), $change));
    }

    public function authorizeChange(MobilityDecisionRequest $request, string $change): JsonResponse
    {
        return $this->response($request, $this->workflow->authorizeBranchChange(
            $this->actor($request), $change, (int) $request->validated('expected_version'),
            (string) $request->validated('reauthentication_token'), $this->correlation($request),
        ));
    }

    public function clientDestinations(BranchClientDestinationsRequest $request, string $change): JsonResponse
    {
        return $this->response($request, $this->workflow->assignBranchClientDestinations(
            $this->actor($request), $change, $request->validated('items'),
            (int) $request->validated('expected_version'),
        ));
    }

    public function destinationCoordinator(DestinationCoordinatorRequest $request, string $change): JsonResponse
    {
        $coordinator = User::query()->where('public_id', $request->validated('destination_coordinator_id'))->firstOrFail();

        return $this->response($request, $this->workflow->assignDestinationCoordinator(
            $this->actor($request), $change, $coordinator->id,
            (int) $request->validated('expected_version'), $this->correlation($request),
        ));
    }

    public function complete(MobilityDecisionRequest $request, string $change): JsonResponse
    {
        return $this->response($request, $this->workflow->completeBranchChange(
            $this->actor($request), $change, (int) $request->validated('expected_version'),
            (string) $request->validated('reauthentication_token'), $this->correlation($request),
        ));
    }

    public function cancel(MobilityDecisionRequest $request, string $change): never
    {
        $this->actor($request);
        $this->workflow->cancelBranchChange();
    }

    private function response(Request $request, object $model, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new MobilityProcessResource($model))->resolve($request)], $status);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $actor->loadMissing('role');

        return $actor;
    }

    private function correlation(Request $request): string
    {
        $id = (string) $request->header('X-Request-Id', '');

        return Str::isUuid($id) ? $id : (string) Str::uuid();
    }
}
