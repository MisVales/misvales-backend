<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Mobility\Application\Queries\MobilityQueryService;
use App\Modules\Mobility\Application\Services\MobilityWorkflowService;
use App\Modules\Mobility\Presentation\Http\Requests\CoordinatorAssignmentsRequest;
use App\Modules\Mobility\Presentation\Http\Requests\CreateCoordinatorBatchRequest;
use App\Modules\Mobility\Presentation\Http\Requests\MobilityDecisionRequest;
use App\Modules\Mobility\Presentation\Http\Requests\MobilityIndexRequest;
use App\Modules\Mobility\Presentation\Http\Resources\MobilityProcessResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

final class CoordinatorReassignmentController extends Controller
{
    public function __construct(
        private readonly MobilityWorkflowService $workflow,
        private readonly MobilityQueryService $queries,
    ) {}

    public function index(MobilityIndexRequest $request): AnonymousResourceCollection
    {
        return MobilityProcessResource::collection($this->queries->coordinatorBatches($this->actor($request), $request->validated()));
    }

    public function store(CreateCoordinatorBatchRequest $request): JsonResponse
    {
        $coordinator = User::query()->where('public_id', $request->validated('outgoing_coordinator_id'))->firstOrFail();
        $branch = Branch::query()->where('public_id', $request->validated('branch_id'))->firstOrFail();
        $model = $this->workflow->createCoordinatorBatch(
            $this->actor($request), $coordinator->id, $branch->id,
            (string) $request->validated('reason'), (string) $request->validated('idempotency_key'),
            (string) $request->validated('reauthentication_token'), $this->correlation($request),
        );

        return $this->response($request, $model, 201);
    }

    public function show(MobilityIndexRequest $request, string $batch): MobilityProcessResource
    {
        return new MobilityProcessResource($this->queries->coordinatorBatch($this->actor($request), $batch));
    }

    public function assignments(CoordinatorAssignmentsRequest $request, string $batch): JsonResponse
    {
        $validated = $request->validated('items');
        abort_unless(is_array($validated), 422);
        $items = [];
        foreach ($validated as $item) {
            abort_unless(is_array($item), 422);
            $coordinator = User::query()->where('public_id', $item['destination_coordinator_id'])->firstOrFail();
            $items[] = [
                'distributor_id' => (string) $item['distributor_id'],
                'destination_coordinator_id' => $coordinator->id,
            ];
        }

        return $this->response($request, $this->workflow->assignDistributorToCoordinator(
            $this->actor($request), $batch, $items, (int) $request->validated('expected_version'),
        ));
    }

    public function validateBatch(MobilityDecisionRequest $request, string $batch): JsonResponse
    {
        return $this->response($request, $this->workflow->validateCoordinatorBatch(
            $this->actor($request), $batch, (int) $request->validated('expected_version'), $this->correlation($request),
        ));
    }

    public function complete(MobilityDecisionRequest $request, string $batch): JsonResponse
    {
        return $this->response($request, $this->workflow->completeCoordinatorBatch(
            $this->actor($request), $batch, (int) $request->validated('expected_version'),
            (string) $request->validated('reauthentication_token'), $this->correlation($request),
        ));
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
