<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ExcessBalance\Application\DTOs\OperationContext;
use App\Modules\ExcessBalance\Application\Services\ChooseCreditBalance;
use App\Modules\ExcessBalance\Application\Services\ExcessQueryService;
use App\Modules\ExcessBalance\Application\Services\RefundEvidenceService;
use App\Modules\ExcessBalance\Application\Services\RefundWorkflowService;
use App\Modules\ExcessBalance\Application\Services\RequestExcessRefund;
use App\Modules\ExcessBalance\Presentation\Http\Requests\ChooseCreditBalanceRequest;
use App\Modules\ExcessBalance\Presentation\Http\Requests\CompleteRefundRequest;
use App\Modules\ExcessBalance\Presentation\Http\Requests\DecideRefundRequest;
use App\Modules\ExcessBalance\Presentation\Http\Requests\ExcessIndexRequest;
use App\Modules\ExcessBalance\Presentation\Http\Requests\RequestRefundRequest;
use App\Modules\ExcessBalance\Presentation\Http\Resources\ExcessApplicationResource;
use App\Modules\ExcessBalance\Presentation\Http\Resources\ExcessBalanceResource;
use App\Modules\ExcessBalance\Presentation\Http\Resources\RefundRequestResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class ExcessBalanceController extends Controller
{
    public function __construct(
        private readonly ExcessQueryService $queries,
        private readonly ChooseCreditBalance $chooseCredit,
        private readonly RequestExcessRefund $requestRefund,
        private readonly RefundWorkflowService $refunds,
        private readonly RefundEvidenceService $evidence,
    ) {}

    public function index(ExcessIndexRequest $request): AnonymousResourceCollection
    {
        return ExcessBalanceResource::collection(
            $this->queries->balances($this->actor($request), $request->filters()),
        );
    }

    public function show(Request $request, string $excessBalance): ExcessBalanceResource
    {
        return new ExcessBalanceResource(
            $this->queries->balance($this->actor($request), $excessBalance),
        );
    }

    public function applications(
        ExcessIndexRequest $request,
        string $excessBalance,
    ): AnonymousResourceCollection {
        return ExcessApplicationResource::collection(
            $this->queries->applications(
                $this->actor($request),
                $excessBalance,
                (int) $request->validated('per_page', 20),
            ),
        );
    }

    public function chooseCredit(
        ChooseCreditBalanceRequest $request,
        string $excessBalance,
    ): JsonResponse {
        return response()->json([
            'data' => $this->chooseCredit->execute(
                $excessBalance,
                (int) $request->validated('lock_version'),
                $this->context($request),
            ),
        ]);
    }

    public function requestRefund(
        RequestRefundRequest $request,
        string $excessBalance,
    ): JsonResponse {
        return response()->json([
            'data' => $this->requestRefund->execute(
                $excessBalance,
                (int) $request->validated('lock_version'),
                $request->validated('reason'),
                $this->context($request),
            ),
        ], 201);
    }

    public function refundIndex(ExcessIndexRequest $request): AnonymousResourceCollection
    {
        return RefundRequestResource::collection(
            $this->queries->refunds($this->actor($request), $request->filters()),
        );
    }

    public function refundShow(Request $request, string $refundRequest): RefundRequestResource
    {
        return new RefundRequestResource(
            $this->queries->refund($this->actor($request), $refundRequest),
        );
    }

    public function authorizeRefund(
        DecideRefundRequest $request,
        string $refundRequest,
    ): JsonResponse {
        return response()->json([
            'data' => $this->refunds->authorize(
                $refundRequest,
                (int) $request->validated('lock_version'),
                (string) $request->validated('reauthentication_token'),
                $request->validated('reason'),
                $this->context($request),
            ),
        ]);
    }

    public function rejectRefund(
        DecideRefundRequest $request,
        string $refundRequest,
    ): JsonResponse {
        return response()->json([
            'data' => $this->refunds->reject(
                $refundRequest,
                (int) $request->validated('lock_version'),
                (string) $request->validated('reauthentication_token'),
                (string) $request->validated('reason'),
                $this->context($request),
            ),
        ]);
    }

    public function completeRefund(
        CompleteRefundRequest $request,
        string $refundRequest,
    ): JsonResponse {
        $file = $request->file('evidence');

        return response()->json([
            'data' => $this->refunds->complete(
                $refundRequest,
                (int) $request->validated('lock_version'),
                CarbonImmutable::parse((string) $request->validated('refund_date'), 'UTC'),
                (string) $request->validated('method'),
                (string) $request->validated('reference'),
                $file instanceof UploadedFile ? $file : null,
                (array) $request->validated('method_fields', []),
                $this->context($request),
            ),
        ]);
    }

    public function evidence(Request $request, string $refundRequest): JsonResponse
    {
        return response()->json([
            'data' => [
                'temporary_url' => $this->evidence->temporaryAccess(
                    $this->actor($request),
                    $refundRequest,
                    $this->context($request),
                ),
            ],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function context(Request $request): OperationContext
    {
        return new OperationContext(
            actor: $this->actor($request),
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
            correlationId: (string) $request->attributes->get('request_id', Str::uuid()),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
