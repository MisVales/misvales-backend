<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Mobility\Application\Queries\MobilityQueryService;
use App\Modules\Mobility\Application\Services\MobilityWorkflowService;
use App\Modules\Mobility\Presentation\Http\Requests\CreateReassignmentRequest;
use App\Modules\Mobility\Presentation\Http\Requests\MobilityDecisionRequest;
use App\Modules\Mobility\Presentation\Http\Requests\MobilityIndexRequest;
use App\Modules\Mobility\Presentation\Http\Resources\MobilityProcessResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

final class AdministrativeReassignmentController extends Controller
{
    public function __construct(
        private readonly MobilityWorkflowService $workflow,
        private readonly MobilityQueryService $queries,
    ) {}

    public function index(MobilityIndexRequest $request): AnonymousResourceCollection
    {
        return MobilityProcessResource::collection($this->queries->reassignments($this->actor($request), $request->validated()));
    }

    public function store(CreateReassignmentRequest $request): JsonResponse
    {
        $model = $this->workflow->createAdministrativeReassignment(
            $this->actor($request),
            $request->validated('items'),
            (string) $request->validated('reason'),
            (string) $request->validated('idempotency_key'),
            $this->correlation($request),
        );

        return $this->response($request, $model, 201);
    }

    public function show(MobilityIndexRequest $request, string $reassignment): MobilityProcessResource
    {
        return new MobilityProcessResource($this->queries->reassignment($this->actor($request), $reassignment));
    }

    public function validateBatch(MobilityDecisionRequest $request, string $reassignment): JsonResponse
    {
        return $this->response($request, $this->workflow->validateAdministrativeReassignment(
            $this->actor($request), $reassignment, (int) $request->validated('expected_version'),
            (string) $request->validated('idempotency_key'), $this->correlation($request),
        ));
    }

    public function complete(MobilityDecisionRequest $request, string $reassignment): JsonResponse
    {
        return $this->response($request, $this->workflow->completeAdministrativeReassignment(
            $this->actor($request), $reassignment, (int) $request->validated('expected_version'),
            (string) $request->validated('reauthentication_token'),
            (string) $request->validated('idempotency_key'), $this->correlation($request),
        ));
    }

    private function response(Request $request, object $model, int $created = 200): JsonResponse
    {
        return response()->json(
            ['data' => (new MobilityProcessResource($model))->resolve($request)],
            $model->getAttribute('_idempotency_replayed') === true ? 200 : $created,
        );
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
