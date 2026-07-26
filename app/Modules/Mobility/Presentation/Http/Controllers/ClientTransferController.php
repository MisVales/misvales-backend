<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Mobility\Application\Queries\MobilityQueryService;
use App\Modules\Mobility\Application\Services\MobilityWorkflowService;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use App\Modules\Mobility\Presentation\Http\Requests\CreateTransferRequest;
use App\Modules\Mobility\Presentation\Http\Requests\MobilityDecisionRequest;
use App\Modules\Mobility\Presentation\Http\Requests\MobilityIndexRequest;
use App\Modules\Mobility\Presentation\Http\Resources\MobilityProcessResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

final class ClientTransferController extends Controller
{
    public function __construct(
        private readonly MobilityWorkflowService $workflow,
        private readonly MobilityQueryService $queries,
    ) {}

    public function index(MobilityIndexRequest $request): AnonymousResourceCollection
    {
        return MobilityProcessResource::collection($this->queries->transfers($this->actor($request), $request->validated()));
    }

    public function recipients(): never
    {
        throw MobilityException::dependencyUnavailable('M15_TRANSFER_RECIPIENT_SCOPE_DEFINITION');
    }

    public function store(CreateTransferRequest $request): JsonResponse
    {
        $transfer = $this->workflow->createTransfer(
            $this->actor($request),
            $request->safe()->except('idempotency_key'),
            (string) $request->validated('idempotency_key'),
            $this->correlation($request),
        );

        return $this->response($request, $transfer, 201);
    }

    public function show(MobilityIndexRequest $request, string $transfer): MobilityProcessResource
    {
        return new MobilityProcessResource($this->queries->transfer($this->actor($request), $transfer));
    }

    public function preaccept(MobilityDecisionRequest $request, string $transfer): JsonResponse
    {
        return $this->response($request, $this->workflow->preaccept(
            $this->actor($request), $transfer, true,
            (int) $request->validated('expected_version'), $request->validated('reason'),
            (string) $request->validated('idempotency_key'), $this->correlation($request),
        ));
    }

    public function rejectPreacceptance(MobilityDecisionRequest $request, string $transfer): JsonResponse
    {
        return $this->response($request, $this->workflow->preaccept(
            $this->actor($request), $transfer, false,
            (int) $request->validated('expected_version'), $request->validated('reason'),
            (string) $request->validated('idempotency_key'), $this->correlation($request),
        ));
    }

    public function originDecision(MobilityDecisionRequest $request, string $transfer): JsonResponse
    {
        return $this->response($request, $this->workflow->decideOrigin(
            $this->actor($request), $transfer, $request->validated('decision') === 'AUTHORIZE',
            (int) $request->validated('expected_version'), $request->validated('reason'),
            (string) $request->validated('idempotency_key'), $this->correlation($request),
        ));
    }

    public function finalAcceptance(MobilityDecisionRequest $request, string $transfer): JsonResponse
    {
        return $this->response($request, $this->workflow->finalizeTransfer(
            $this->actor($request), $transfer, (int) $request->validated('expected_version'),
            (string) $request->validated('idempotency_key'), $this->correlation($request),
        ));
    }

    public function cancel(MobilityDecisionRequest $request, string $transfer): never
    {
        $this->actor($request);
        $this->workflow->cancelTransfer();
    }

    private function response(Request $request, object $model, int $created = 200): JsonResponse
    {
        $status = $model->getAttribute('_idempotency_replayed') === true ? 200 : $created;

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
        $value = (string) $request->header('X-Request-Id', '');

        return Str::isUuid($value) ? $value : (string) Str::uuid();
    }
}
