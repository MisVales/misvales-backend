<?php

declare(strict_types=1);

namespace App\Modules\Credit\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Credit\Application\Services\CreditIncreaseService;
use App\Modules\Credit\Application\Services\CreditQueryService;
use App\Modules\Credit\Domain\Enums\IncreaseOriginType;
use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use App\Modules\Credit\Presentation\Http\Requests\CreateCreditIncreaseRequest;
use App\Modules\Credit\Presentation\Http\Requests\CreditIncreaseIndexRequest;
use App\Modules\Credit\Presentation\Http\Requests\ManagerCreditIncreaseDecisionRequest;
use App\Modules\Credit\Presentation\Http\Requests\ReviewCreditIncreaseRequest;
use App\Modules\Credit\Presentation\Http\Resources\CreditIncreaseRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CreditIncreaseController extends Controller
{
    public function __construct(
        private readonly CreditIncreaseService $service,
        private readonly CreditQueryService $queries,
    ) {}

    public function store(CreateCreditIncreaseRequest $request, User $distributor): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $origin = IncreaseOriginType::from((string) $request->input('origin.type', IncreaseOriginType::NORMAL->value));
        $product = $request->input('origin.product_amount');
        $result = $this->service->request(
            $actor,
            $distributor,
            new Money($request->string('requested_amount')->toString()),
            $request->string('reason')->toString(),
            $origin,
            is_string($product) ? new Money($product) : null,
            $request->string('idempotency_key')->toString(),
        );

        return (new CreditIncreaseRequestResource($result))->response()->setStatusCode(201);
    }

    public function index(CreditIncreaseIndexRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $paginator = $this->queries->increaseRequests($actor, $request->validated());

        return response()->json(CreditIncreaseRequestResource::collection($paginator)->response()->getData(true));
    }

    public function show(Request $request, CreditIncreaseRequestModel $creditIncreaseRequest): CreditIncreaseRequestResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new CreditIncreaseRequestResource($this->queries->increaseRequest($actor, $creditIncreaseRequest));
    }

    public function review(
        ReviewCreditIncreaseRequest $request,
        CreditIncreaseRequestModel $creditIncreaseRequest,
    ): CreditIncreaseRequestResource {
        /** @var User $actor */
        $actor = $request->user();
        $amount = $request->input('recommended_amount');
        $result = $this->service->review(
            $actor,
            $creditIncreaseRequest,
            $request->string('decision')->toString(),
            is_string($amount) ? new Money($amount) : null,
            $request->string('reason')->toString(),
            $request->has('lock_version') ? $request->integer('lock_version') : null,
        );

        return new CreditIncreaseRequestResource($result);
    }

    public function managerDecision(
        ManagerCreditIncreaseDecisionRequest $request,
        CreditIncreaseRequestModel $creditIncreaseRequest,
    ): CreditIncreaseRequestResource {
        /** @var User $actor */
        $actor = $request->user();
        $amount = $request->input('authorized_amount');
        $result = $this->service->managerDecision(
            $actor,
            $creditIncreaseRequest,
            $request->string('decision')->toString(),
            is_string($amount) ? new Money($amount) : null,
            $request->string('reason')->toString(),
            $request->reauthenticationToken(),
            $request->has('lock_version') ? $request->integer('lock_version') : null,
        );

        return new CreditIncreaseRequestResource($result);
    }
}
