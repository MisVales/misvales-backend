<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Voucher\Application\Commands\GenerateVoucher\Command as GenerateVoucherCommand;
use App\Modules\Voucher\Application\Commands\GenerateVoucher\Handler as GenerateVoucherHandler;
use App\Modules\Voucher\Application\DTOs\OperationMetadata;
use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Application\Security\VoucherActorContextFactory;
use App\Modules\Voucher\Application\Services\CounterVoucherService;
use App\Modules\Voucher\Application\Services\ModificationWorkflowService;
use App\Modules\Voucher\Domain\Enums\VoucherRejectionReason;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\DataChangeRequestModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;
use App\Modules\Voucher\Presentation\Http\Requests\FulfillVoucherRequest;
use App\Modules\Voucher\Presentation\Http\Requests\GenerateVoucherRequest;
use App\Modules\Voucher\Presentation\Http\Requests\OpenVoucherRequest;
use App\Modules\Voucher\Presentation\Http\Requests\RejectVoucherRequest;
use App\Modules\Voucher\Presentation\Http\Requests\ReleaseVoucherRequest;
use App\Modules\Voucher\Presentation\Http\Requests\RequestModificationRequest;
use App\Modules\Voucher\Presentation\Http\Requests\SearchVouchersRequest;
use App\Modules\Voucher\Presentation\Http\Resources\ModificationRequestResource;
use App\Modules\Voucher\Presentation\Http\Resources\VoucherResource;
use App\Modules\Voucher\Presentation\Http\Resources\VoucherSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class VoucherController extends Controller
{
    public function __construct(
        private readonly CounterVoucherService $vouchers,
        private readonly ModificationWorkflowService $modifications,
        private readonly VoucherActorContextFactory $contexts,
        private readonly GenerateVoucherHandler $generate,
    ) {}

    public function store(GenerateVoucherRequest $request): JsonResponse
    {
        Gate::authorize('generate', VoucherModel::class);
        $user = $request->user();
        if (! $user instanceof User) {
            throw VoucherDomainException::scopeDenied();
        }
        $result = $this->generate->handle(new GenerateVoucherCommand(
            actor: $user,
            clientId: (string) $request->validated('client_id'),
            productId: (string) $request->validated('product_id'),
            metadata: $this->metadata($request),
        ));

        return response()->json(
            ['data' => $result->data],
            $result->replayed ? 200 : 201,
            ['X-Request-Id' => $this->requestId($request)],
        );
    }

    public function index(SearchVouchersRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', VoucherModel::class);

        return VoucherSummaryResource::collection(
            $this->vouchers->search($this->actor($request), $request->validated()),
        );
    }

    public function show(Request $request, string $voucher): VoucherResource
    {
        Gate::authorize('viewAny', VoucherModel::class);

        return new VoucherResource($this->vouchers->detail(
            $voucher,
            $this->actor($request),
            $this->metadata($request),
        ));
    }

    public function open(OpenVoucherRequest $request, string $voucher): VoucherResource
    {
        Gate::authorize('openAtCounter', VoucherModel::class);

        return new VoucherResource($this->vouchers->open(
            $voucher,
            (int) $request->validated('lock_version'),
            $this->actor($request),
            $this->metadata($request),
        ));
    }

    public function release(ReleaseVoucherRequest $request, string $voucher): VoucherResource
    {
        Gate::authorize('release', VoucherModel::class);

        return new VoucherResource($this->vouchers->release(
            $voucher,
            (int) $request->validated('lock_version'),
            $request->validated('checks'),
            $this->actor($request),
            $this->metadata($request),
        ));
    }

    public function reject(RejectVoucherRequest $request, string $voucher): VoucherResource
    {
        Gate::authorize('reject', VoucherModel::class);

        return new VoucherResource($this->vouchers->reject(
            $voucher,
            (int) $request->validated('lock_version'),
            VoucherRejectionReason::from((string) $request->validated('reason_code')),
            (string) $request->validated('description'),
            $this->actor($request),
            $this->metadata($request),
        ));
    }

    public function fulfill(FulfillVoucherRequest $request, string $voucher): VoucherResource
    {
        Gate::authorize('fulfill', VoucherModel::class);

        return new VoucherResource($this->vouchers->fulfill(
            $voucher,
            (int) $request->validated('lock_version'),
            (string) $request->validated('transaction_number'),
            $this->actor($request),
            $this->metadata($request),
        ));
    }

    public function requestModification(
        RequestModificationRequest $request,
        string $voucher,
    ): ModificationRequestResource {
        Gate::authorize('create', DataChangeRequestModel::class);

        return new ModificationRequestResource($this->modifications->request(
            $voucher,
            $request->validated('fields'),
            (string) $request->validated('reason'),
            (int) $request->validated('lock_version'),
            $this->actor($request),
            $this->metadata($request),
        ));
    }

    private function actor(Request $request): VoucherActorContext
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw VoucherDomainException::scopeDenied();
        }

        return $this->contexts->fromUser($user);
    }

    private function metadata(Request $request): OperationMetadata
    {
        return new OperationMetadata(
            requestId: $this->requestId($request),
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId) ? $requestId : (string) Str::uuid();
    }
}
